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
        if (($n['data']['bundleId'] ?? null) !== Config::get('subscriptions.apple.bundle_id') || empty($n['data']['signedTransactionInfo'])) {
            return Response::noContent();
        }
        $tx = AppleJws::verify((string) $n['data']['signedTransactionInfo']);
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
        Logger::info('App Store : notification', ['type' => $type, 'subtype' => $subtype]);

        return Response::noContent();
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
        ];
        if ($existing === null) {
            Database::execute(
                'INSERT INTO subscriptions (store, original_transaction_id, user_id, product_id, status, auto_renew, expires_at, environment, last_notification, created_at, updated_at)
                 VALUES (\'apple\', :o, :user_id, :product_id, :status, :auto_renew, :expires_at, :environment, :last_notification, :c, :updated_at)',
                $fields + ['o' => (string) $tx['originalTransactionId'], 'c' => $now],
            );
        } else {
            Database::execute(
                'UPDATE subscriptions SET user_id = :user_id, product_id = :product_id, status = :status, auto_renew = :auto_renew, expires_at = :expires_at,
                        environment = :environment, last_notification = :last_notification, updated_at = :updated_at WHERE id = :id',
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
