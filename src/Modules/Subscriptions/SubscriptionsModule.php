<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Support\Validator;

/**
 * My Clermont+ avec StoreKit 2 (spec v1) :
 *   GET  /me/subscription                 { active, productId, expiresAt, autoRenew, environment }
 *   POST /subscriptions/apple/verify      { signedTransaction }  → { active, productId, expiresAt, autoRenew }
 *   POST /webhooks/apple/app-store        { signedPayload }      notifications serveur v2 (route non signée, appelée par Apple)
 *   POST /me/subscription                 { signedTransaction, appAccountToken? } → { isMember, productId, expiresAt, inTrial, willRenew } (9cdabedf)
 *   POST /webhooks/app-store              même chose que /webhooks/apple/app-store (URL saisie dans App Store Connect)
 *
 * Le serveur seul décide : chaque route réservée contrôle users.premium_until, jamais la parole de l'app.
 */
final class SubscriptionsModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->get('/me/subscription', [self::class, 'show']);
            $r->post('/subscriptions/apple/verify', [self::class, 'verify']);
            $r->post('/me/subscription', [self::class, 'attach']);
        });
    }

    public function show(Request $request): Response
    {
        $s = Database::fetch('SELECT * FROM subscriptions WHERE user_id = :u ORDER BY expires_at DESC LIMIT 1', ['u' => $request->attributes['user_id']]);

        return Response::success(self::present($s));
    }

    public function verify(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['signed_transaction' => 'required|string|max:20000']);
        $tx = AppleJws::verify($data['signed_transaction']);
        $user = Database::fetch('SELECT id, uid FROM users WHERE id = :id', ['id' => $request->attributes['user_id']]);

        if (($tx['bundleId'] ?? null) !== Config::get('subscriptions.apple.bundle_id')
            || !in_array($tx['productId'] ?? null, (array) Config::get('subscriptions.apple.product_ids'), true)) {
            throw new ValidationException(['signed_transaction' => 'Produit inconnu.']);
        }
        // L'app achète avec appAccountToken = user.id : la transaction est liée au compte
        if (strtolower((string) ($tx['appAccountToken'] ?? '')) !== $user['uid']) {
            throw HttpException::forbidden('forbidden', 'Cet achat appartient à un autre compte.');
        }
        if (($tx['environment'] ?? 'Production') === 'Sandbox' && !Config::get('subscriptions.apple.allow_sandbox')) {
            throw new ValidationException(['signed_transaction' => 'Achat de test refusé en production.']);
        }
        $status = isset($tx['revocationDate']) ? 'revoked' : ((int) ($tx['expiresDate'] ?? 0) / 1000 > time() ? 'active' : 'expired');
        $sub = self::upsert((int) $user['id'], $tx, $status, null, 'VERIFY');

        return Response::success(array_diff_key(self::present($sub), ['environment' => 1]));
    }

    /** POST /me/subscription (9cdabedf) : après un achat, un renouvellement, une restauration ou au lancement */
    public function attach(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['signed_transaction' => 'required|string|max:20000', 'app_account_token' => 'nullable|string|max:64']);
        try {
            $tx = AppleJws::verify($data['signed_transaction']);
        } catch (HttpException) {
            throw self::invalidTransaction('Signature App Store invalide.');
        }
        $user = Database::fetch('SELECT id, uid FROM users WHERE id = :id', ['id' => $request->attributes['user_id']]);
        if (($tx['bundleId'] ?? null) !== Config::get('subscriptions.apple.bundle_id') || !in_array($tx['productId'] ?? null, (array) Config::get('subscriptions.apple.product_ids'), true)
            || empty($tx['originalTransactionId'])) {
            throw self::invalidTransaction('Produit inconnu.');
        }
        if (($tx['environment'] ?? 'Production') === 'Sandbox' && !Config::get('subscriptions.apple.allow_sandbox')) {
            throw self::invalidTransaction('Achat de test refusé en production.');
        }
        $token = strtolower((string) ($tx['appAccountToken'] ?? $data['app_account_token'] ?? ''));
        if ($token !== '' && $token !== self::accountToken((string) $user['uid'])) {
            throw self::ownedByOther();
        }
        $owner = Database::fetch("SELECT user_id FROM subscriptions WHERE store = 'apple' AND original_transaction_id = :o", ['o' => (string) $tx['originalTransactionId']])['user_id'] ?? null;
        if ($owner !== null && (int) $owner !== (int) $user['id']) {
            throw self::ownedByOther();
        }

        // État réel auprès d'Apple quand la clé est installée, sinon la transaction signée fait foi
        $live = AppStoreApi::latest((string) $tx['originalTransactionId'], (string) ($tx['environment'] ?? 'Production'));
        if ($live !== null) {
            $tx = $live['tx'];
            $status = [1 => 'active', 2 => 'expired', 3 => 'expired', 4 => 'grace', 5 => 'revoked'][$live['status']] ?? null;
            $autoRenew = isset($live['renewal']['autoRenewStatus']) ? (int) $live['renewal']['autoRenewStatus'] === 1 : null;
        } else {
            $status = isset($tx['revocationDate']) ? 'revoked' : ((int) ($tx['expiresDate'] ?? 0) / 1000 > time() ? 'active' : 'expired');
            $autoRenew = null;
        }
        self::upsert((int) $user['id'], $tx, $status, $autoRenew, 'ATTACH');

        return Response::success(self::membership((int) $user['id']) ?? ['is_member' => false, 'product_id' => null, 'expires_at' => null, 'in_trial' => false, 'will_renew' => false]);
    }

    /** { isMember, productId, expiresAt, inTrial, willRenew } du dernier abonnement App Store du compte, ou null (GET /me, POST /me/subscription) */
    public static function membership(int $userId): ?array
    {
        $s = Database::fetch("SELECT * FROM subscriptions WHERE user_id = :u AND store = 'apple' ORDER BY expires_at DESC LIMIT 1", ['u' => $userId]);
        if ($s === null) {
            return null;
        }
        $p = self::present($s);

        return ['is_member' => $p['active'], 'product_id' => $p['product_id'], 'expires_at' => $p['expires_at'], 'in_trial' => $p['active'] && (bool) $s['in_trial'],
                'will_renew' => $p['active'] && $p['auto_renew']];
    }

    /**
     * Membre ? Si le compte n'a rien d'actif mais un abonnement connu, on redemande à Apple avant de refuser
     * (« ne bloque jamais un membre », 9cdabedf).
     */
    public static function isMember(int $userId, bool $confirmWithApple = false): bool
    {
        if (\App\Modules\Premium\PremiumService::isActiveById($userId)) {
            return true;
        }
        if (!$confirmWithApple || !AppStoreApi::configured()) {
            return false;
        }
        foreach (Database::fetchAll("SELECT original_transaction_id, environment FROM subscriptions WHERE user_id = :u AND store = 'apple' ORDER BY updated_at DESC LIMIT 2", ['u' => $userId]) as $s) {
            $live = AppStoreApi::latest((string) $s['original_transaction_id'], (string) $s['environment']);
            if ($live !== null) {
                $status = [1 => 'active', 2 => 'expired', 3 => 'expired', 4 => 'grace', 5 => 'revoked'][$live['status']] ?? null;
                self::upsert($userId, $live['tx'], $status, isset($live['renewal']['autoRenewStatus']) ? (int) $live['renewal']['autoRenewStatus'] === 1 : null, 'CONFIRM');
            }
        }

        return \App\Modules\Premium\PremiumService::isActiveById($userId);
    }

    /** appAccountToken de l'app : l'uid s'il est déjà un UUID, sinon UUID v5-like tiré du SHA-256 de l'id */
    public static function accountToken(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return strtolower($id);
        }
        $b = substr(hash('sha256', $id, true), 0, 16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x50);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** Suppression du compte : l'abonnement Apple est détaché (la résiliation se fait dans les réglages Apple) */
    public static function detach(int $userId): void
    {
        Database::execute("UPDATE subscriptions SET user_id = NULL, updated_at = :d WHERE user_id = :u", ['d' => gmdate('Y-m-d H:i:s'), 'u' => $userId]);
    }

    private static function invalidTransaction(string $why): HttpException
    {
        return new HttpException(400, 'invalid_transaction', 'Achat App Store non reconnu : ' . lcfirst($why));
    }

    private static function ownedByOther(): HttpException
    {
        return new HttpException(409, 'conflict', 'Cet abonnement My Clermont+ est déjà rattaché à un autre compte MyClermont. Connecte-toi avec ce compte pour en profiter.', ['reason' => 'owned_by_other_account']);
    }

    /** Notifications serveur App Store v2. Toujours 200 une fois la signature vérifiée (Apple réessaie sinon). */
    public static function webhook(Request $request): Response
    {
        $payload = $request->json()['signed_payload'] ?? null;
        if (!is_string($payload)) {
            throw new ValidationException(['signedPayload' => 'Requis.']);
        }
        $n = AppleJws::verify($payload);
        $type = (string) ($n['notificationType'] ?? '');
        $subtype = (string) ($n['subtype'] ?? '');
        if ($type === 'TEST') {
            Logger::info('App Store : notification de test reçue');
            return Response::success(['received' => true]);
        }
        if (($n['data']['bundleId'] ?? null) !== Config::get('subscriptions.apple.bundle_id') || empty($n['data']['signedTransactionInfo'])) {
            return Response::success(['received' => true]);
        }
        $tx = AppleJws::verify((string) $n['data']['signedTransactionInfo']);
        // Notifications en double ou en désordre : seule la plus récente (signedDate) compte
        $tx['_signed_date'] = (int) ($n['signedDate'] ?? 0);
        $last = Database::fetch("SELECT last_signed_at FROM subscriptions WHERE store = 'apple' AND original_transaction_id = :o", ['o' => (string) $tx['originalTransactionId']]);
        if ($last !== null && $last['last_signed_at'] !== null && $tx['_signed_date'] > 0 && $tx['_signed_date'] <= (int) $last['last_signed_at']) {
            Logger::info('App Store : notification plus ancienne ignorée', ['type' => $type, 'original_transaction_id' => (string) $tx['originalTransactionId']]);
            return Response::success(['received' => true]);
        }
        $renewal = !empty($n['data']['signedRenewalInfo']) ? AppleJws::verify((string) $n['data']['signedRenewalInfo']) : [];

        $userId = Database::fetch("SELECT user_id FROM subscriptions WHERE store = 'apple' AND original_transaction_id = :o", ['o' => (string) $tx['originalTransactionId']])['user_id']
            ?? Database::fetch('SELECT id FROM users WHERE uid = :u', ['u' => strtolower((string) ($tx['appAccountToken'] ?? ''))])['id'] ?? null;
        $status = match ($type) {
            'SUBSCRIBED', 'DID_RENEW', 'OFFER_REDEEMED' => 'active',
            'DID_FAIL_TO_RENEW' => $subtype === 'GRACE_PERIOD' ? 'grace' : 'expired',
            'GRACE_PERIOD_EXPIRED', 'EXPIRED' => 'expired',
            'REFUND' => 'refunded',
            'REVOKE' => 'revoked',
            default => null,
        };
        $autoRenew = isset($renewal['autoRenewStatus']) ? (int) $renewal['autoRenewStatus'] === 1 : null;
        if ($type === 'DID_CHANGE_RENEWAL_STATUS') {
            $autoRenew = $subtype === 'AUTO_RENEW_ENABLED';
        }
        if ($status !== null || $autoRenew !== null) {
            self::upsert($userId === null ? null : (int) $userId, $tx, $status, $autoRenew, $type . ($subtype ? ':' . $subtype : ''));
        }
        Logger::info('App Store : notification', ['type' => $type, 'subtype' => $subtype, 'original_transaction_id' => (string) $tx['originalTransactionId']]);

        return Response::success(['received' => true]);
    }

    private static function upsert(?int $userId, array $tx, ?string $status, ?bool $autoRenew, string $event): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $expires = isset($tx['expiresDate']) ? gmdate('Y-m-d H:i:s', (int) ($tx['expiresDate'] / 1000)) : null;
        $existing = Database::fetch("SELECT * FROM subscriptions WHERE store = 'apple' AND original_transaction_id = :o", ['o' => (string) $tx['originalTransactionId']]);
        $status ??= $existing['status'] ?? 'active';
        $fields = [
            'user_id' => $userId ?? $existing['user_id'] ?? null, 'product_id' => (string) $tx['productId'], 'status' => $status,
            'auto_renew' => (int) ($autoRenew ?? (bool) ($existing['auto_renew'] ?? true)), 'expires_at' => $expires ?? $existing['expires_at'] ?? null,
            'environment' => ($tx['environment'] ?? 'Production') === 'Sandbox' ? 'Sandbox' : 'Production', 'last_notification' => substr($event, 0, 40), 'updated_at' => $now,
            // Essai gratuit = offre d'introduction (offerType 1) de la transaction en cours
            'in_trial' => (int) ((int) ($tx['offerType'] ?? 0) === 1 && ($tx['offerDiscountType'] ?? 'FREE_TRIAL') === 'FREE_TRIAL'),
            'last_signed_at' => isset($tx['_signed_date']) && $tx['_signed_date'] > 0 ? (int) $tx['_signed_date'] : ($existing['last_signed_at'] ?? null),
        ];
        if ($existing === null) {
            Database::execute(
                'INSERT INTO subscriptions (store, original_transaction_id, user_id, product_id, status, auto_renew, expires_at, environment, last_notification, in_trial, last_signed_at, created_at, updated_at)
                 VALUES (\'apple\', :o, :user_id, :product_id, :status, :auto_renew, :expires_at, :environment, :last_notification, :in_trial, :last_signed_at, :c, :updated_at)',
                $fields + ['o' => (string) $tx['originalTransactionId'], 'c' => $now],
            );
        } else {
            Database::execute(
                'UPDATE subscriptions SET user_id = :user_id, product_id = :product_id, status = :status, auto_renew = :auto_renew, expires_at = :expires_at,
                        environment = :environment, last_notification = :last_notification, in_trial = :in_trial, last_signed_at = :last_signed_at, updated_at = :updated_at WHERE id = :id',
                $fields + ['id' => $existing['id']],
            );
        }
        if ($fields['user_id'] !== null) {
            // Remboursement / révocation : accès retiré tout de suite. Délai de grâce : l'accès continue jusqu'à expires_at.
            $until = in_array($status, ['revoked', 'refunded'], true) ? $now : $fields['expires_at'];
            Database::execute("UPDATE users SET premium_until = :p, premium_source = 'apple', updated_at = :d WHERE id = :id", ['p' => $until, 'd' => $now, 'id' => $fields['user_id']]);
        }

        return Database::fetch("SELECT * FROM subscriptions WHERE store = 'apple' AND original_transaction_id = :o", ['o' => (string) $tx['originalTransactionId']]);
    }

    private static function present(?array $s): array
    {
        $active = $s !== null && in_array($s['status'], ['active', 'grace'], true) && $s['expires_at'] !== null && strtotime($s['expires_at'] . ' UTC') > time();

        return [
            'active'      => $active,
            'product_id'  => $s['product_id'] ?? null,
            'expires_at'  => isset($s['expires_at']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($s['expires_at'] . ' UTC')) : null,
            'auto_renew'  => $s !== null && (bool) $s['auto_renew'],
            'environment' => $s['environment'] ?? null,
        ];
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
