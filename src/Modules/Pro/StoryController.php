<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Places\MapController;
use App\Security\Crypto;

/**
 * Stories des établissements (demande b5b4c116) : 24 h en ligne, programmables 7 jours à l'avance.
 *
 * Espace pro (session web) :
 *   GET /pro/stories → { items: ProStory[] } ; POST /pro/stories ; PATCH|DELETE /pro/stories/{id}
 *   Quota par jour calendaire (Europe/Paris) : 2 en gratuit, 10 en partenaire → 409 story_daily_limit.
 * App (signature appareil, compte facultatif) :
 *   GET /stories → { items: [{ author: { placeId, name, avatar, isPartner, spot: MapSpot }, stories: [...] }], nextCursor }
 *   POST /stories/{id}/view, POST /stories/{id}/cta → 204 (une fois par appareil)
 *
 * Statut calculé : programmee (avant startsAt), en_ligne, expiree (après expiresAt), retiree (par le commerçant ou la modération).
 */
final class StoryController
{
    private const STYLES = ['bandeau', 'plein', 'aucun'];
    private const CTA_KINDS = ['place', 'deal', 'event', 'url'];

    public function index(Request $request): Response
    {
        $rows = Database::fetchAll('SELECT * FROM pro_stories WHERE business_id = :b ORDER BY starts_at DESC, id DESC LIMIT 200', ['b' => Pro::business($request)['id']]);

        return Response::success(array_map([self::class, 'present'], $rows));
    }

    public function create(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        Verification::require($request);
        $business = Pro::business($request);
        $d = self::validate($request->json(), null, $business);
        self::quota($business, $d['starts_at'], null);
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert(
            'INSERT INTO pro_stories (uid, business_id, place_id, media_kind, media_url, duration_sec, caption, caption_style, cta, starts_at, expires_at, created_at, updated_at)
             VALUES (:u, :b, :p, :k, :url, :dur, :c, :s, :cta, :st, :ex, :n, :n2)',
            ['u' => Crypto::uuid4(), 'b' => $business['id'], 'p' => $business['place_id'], 'k' => $d['media_kind'], 'url' => $d['media_url'], 'dur' => $d['duration_sec'],
             'c' => $d['caption'], 's' => $d['caption_style'], 'cta' => $d['cta'], 'st' => $d['starts_at'], 'ex' => $d['expires_at'], 'n' => $now, 'n2' => $now],
        );

        return Response::success(self::present($this->row($id)), 201);
    }

    public function update(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $story = $this->find($request);
        $json = $request->json();
        if (($json['status'] ?? null) === 'retiree') {
            Database::execute("UPDATE pro_stories SET removed_at = COALESCE(removed_at, :n), removed_by = COALESCE(removed_by, 'pro'), updated_at = :n2 WHERE id = :id",
                ['n' => gmdate('Y-m-d H:i:s'), 'n2' => gmdate('Y-m-d H:i:s'), 'id' => $story['id']]);

            return Response::success(self::present($this->row((int) $story['id'])));
        }
        if ($story['removed_at'] !== null) {
            throw new HttpException(409, 'conflict', 'Cette story est retirée : crée-en une nouvelle.', ['reason' => 'story_removed']);
        }
        $business = Pro::business($request);
        $d = self::validate($json, $story, $business);
        if ($d['starts_at'] !== $story['starts_at']) {
            if (strtotime($story['starts_at'] . ' UTC') <= time()) {
                throw new ValidationException(['starts_at' => 'Une story déjà diffusée ne peut plus être reprogrammée.']);
            }
            self::quota($business, $d['starts_at'], (int) $story['id']);
        }
        Database::execute(
            'UPDATE pro_stories SET media_kind = :k, media_url = :url, duration_sec = :dur, caption = :c, caption_style = :s, cta = :cta, starts_at = :st, expires_at = :ex, updated_at = :n WHERE id = :id',
            ['k' => $d['media_kind'], 'url' => $d['media_url'], 'dur' => $d['duration_sec'], 'c' => $d['caption'], 's' => $d['caption_style'], 'cta' => $d['cta'],
             'st' => $d['starts_at'], 'ex' => $d['expires_at'], 'n' => gmdate('Y-m-d H:i:s'), 'id' => $story['id']],
        );

        return Response::success(self::present($this->row((int) $story['id'])));
    }

    public function destroy(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $story = $this->find($request);
        Database::execute('DELETE FROM pro_story_hits WHERE story_id = :id', ['id' => $story['id']]);
        // Une story déjà diffusée reste comptée dans le quota du jour : elle est seulement retirée
        if (strtotime($story['starts_at'] . ' UTC') <= time()) {
            Database::execute("UPDATE pro_stories SET removed_at = COALESCE(removed_at, :n), removed_by = COALESCE(removed_by, 'pro') WHERE id = :id", ['n' => gmdate('Y-m-d H:i:s'), 'id' => $story['id']]);
        } else {
            Database::execute('DELETE FROM pro_stories WHERE id = :id', ['id' => $story['id']]);
        }

        return Response::noContent();
    }

    /** App : stories en ligne, un groupe par établissement, partenaires d'abord */
    public function feed(Request $request): Response
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = Database::fetchAll(
            'SELECT s.*, s.uid AS story_uid, ' . MapController::columns() . ', COALESCE(p.cover_url, (SELECT ph.url FROM place_photos ph WHERE ph.place_id = p.id ORDER BY ph.position LIMIT 1)) AS avatar
             FROM pro_stories s JOIN pro_businesses b ON b.id = s.business_id AND b.deleted_at IS NULL
             JOIN places p ON p.id = s.place_id AND p.status = \'published\' AND p.deleted_at IS NULL JOIN place_categories c ON c.id = p.category_id
             WHERE s.removed_at IS NULL AND s.starts_at <= :n AND s.expires_at > :n2
             ORDER BY s.starts_at, s.id LIMIT 500',
            ['n' => $now, 'n2' => $now],
        );
        $groups = [];
        foreach ($rows as $r) {
            $pid = (int) $r['place_id'];
            if (!isset($groups[$pid])) {
                $spot = MapController::place($r);
                $groups[$pid] = ['author' => [
                    'place_id' => $spot['id'], 'name' => $r['name'], 'avatar' => is_string($r['avatar']) && str_starts_with($r['avatar'], 'https://') ? $r['avatar'] : null,
                    'is_partner' => (bool) $r['is_partner_active'], 'spot' => $spot,
                ], 'stories' => [], 'latest' => $r['starts_at']];
            }
            $groups[$pid]['stories'][] = self::presentApp(['uid' => $r['story_uid']] + $r);
            $groups[$pid]['latest'] = max($groups[$pid]['latest'], $r['starts_at']);
        }
        $groups = array_values($groups);
        usort($groups, static fn ($a, $b) => [(int) $b['author']['is_partner'], $b['latest']] <=> [(int) $a['author']['is_partner'], $a['latest']]);

        return Response::success(array_map(static fn ($g) => ['author' => $g['author'], 'stories' => $g['stories']], $groups), 200, ['next_cursor' => null])
            ->withHeader('Cache-Control', 'private, max-age=60');
    }

    public function view(Request $request): Response
    {
        return $this->hit($request, 'view', 'views');
    }

    public function cta(Request $request): Response
    {
        return $this->hit($request, 'cta', 'cta_taps');
    }

    private function hit(Request $request, string $type, string $column): Response
    {
        $story = Database::fetch('SELECT id FROM pro_stories WHERE uid = :u', ['u' => strtolower((string) $request->param('id'))])
            ?? throw new HttpException(404, 'not_found', 'Story introuvable.');
        $device = (int) ($request->attributes['device']['id'] ?? 0);
        if ($device > 0 && Database::execute('INSERT IGNORE INTO pro_story_hits (story_id, device_id, type, created_at) VALUES (:s, :d, :t, :n)',
            ['s' => $story['id'], 'd' => $device, 't' => $type, 'n' => gmdate('Y-m-d H:i:s')]) > 0) {
            Database::execute("UPDATE pro_stories SET {$column} = {$column} + 1 WHERE id = :id", ['id' => $story['id']]);
        }

        return Response::noContent();
    }

    public static function present(array $s): array
    {
        return [
            'id'            => $s['uid'],
            'media'         => ['kind' => $s['media_kind'], 'url' => $s['media_url'], 'duration_sec' => (int) $s['duration_sec']],
            'caption'       => (string) $s['caption'],
            'caption_style' => $s['caption_style'],
            'cta'           => self::ctaOf($s['cta']),
            'status'        => self::status($s),
            'starts_at'     => Pro::iso($s['starts_at']),
            'expires_at'    => Pro::iso($s['expires_at']),
            'created_at'    => Pro::iso($s['created_at']),
            'stats'         => ['views' => (int) $s['views'], 'cta_taps' => (int) $s['cta_taps']],
        ];
    }

    private static function presentApp(array $s): array
    {
        $p = self::present($s);

        return ['id' => $p['id'], 'media' => $p['media'], 'caption' => $p['caption'], 'caption_style' => $p['caption_style'], 'cta' => $p['cta'],
            'starts_at' => $p['starts_at'], 'expires_at' => $p['expires_at']];
    }

    public static function status(array $s): string
    {
        return match (true) {
            $s['removed_at'] !== null                       => 'retiree',
            strtotime($s['starts_at'] . ' UTC') > time()    => 'programmee',
            strtotime($s['expires_at'] . ' UTC') <= time()  => 'expiree',
            default                                         => 'en_ligne',
        };
    }

    private static function ctaOf(?string $json): ?array
    {
        $c = $json === null ? null : json_decode($json, true);

        return is_array($c) ? ['kind' => $c['kind'], 'label' => $c['label'], 'target_id' => $c['target_id'] ?? null, 'url' => $c['url'] ?? null] : null;
    }

    /** Retrait par la modération (back-office) : la story passe en « retiree », le commerçant est prévenu */
    public static function moderate(string $uid, bool $remove, string $reason): void
    {
        $s = Database::fetch('SELECT id, business_id FROM pro_stories WHERE uid = :u', ['u' => $uid]) ?? throw new \DomainException('Story introuvable.');
        $now = gmdate('Y-m-d H:i:s');
        Database::execute($remove
            ? "UPDATE pro_stories SET removed_at = COALESCE(removed_at, :n), removed_by = 'moderation', reviewed_at = :n2 WHERE id = :id"
            : 'UPDATE pro_stories SET reviewed_at = :n2 WHERE id = :id AND :n IS NOT NULL', ['n' => $now, 'n2' => $now, 'id' => $s['id']]);
        if ($remove) {
            Pro::notify((int) $s['business_id'], 'moderation', 'Story retirée', $reason ?: 'Ta story ne respecte pas les règles de publication : elle a été retirée.', '/stories');
        }
    }

    /** @return array{media_kind: string, media_url: string, duration_sec: int, caption: string, caption_style: string, cta: ?string, starts_at: string, expires_at: string} */
    private static function validate(array $j, ?array $current, array $business): array
    {
        $errors = [];
        $d = $current === null
            ? ['media_kind' => null, 'media_url' => null, 'duration_sec' => 6, 'caption' => '', 'caption_style' => 'bandeau', 'cta' => null, 'starts_at' => null]
            : array_intersect_key($current, array_flip(['media_kind', 'media_url', 'duration_sec', 'caption', 'caption_style', 'cta', 'starts_at']));

        if ($current === null || array_key_exists('media', $j)) {
            $m = is_array($j['media'] ?? null) ? $j['media'] : [];
            $base = (string) Config::get('app.media_base_url') . '/';
            if (!in_array($m['kind'] ?? null, ['image', 'video'], true)) {
                $errors['media'] = 'Ajoute une photo ou une vidéo.';
            } elseif (!is_string($m['url'] ?? null) || !str_starts_with($m['url'], $base)) {
                $errors['media'] = 'Envoie le fichier avec POST /pro/uploads (purpose « story ») avant de créer la story.';
            } else {
                $media = Database::fetch('SELECT kind FROM media WHERE path = :p', ['p' => substr($m['url'], strlen($base))]);
                $kind = $media['kind'] ?? $m['kind'];
                $dur = $kind === 'image' ? 6 : (int) round((float) ($m['duration_sec'] ?? 15));
                if ($kind === 'video' && ($dur < 1 || $dur > 15)) {
                    $errors['media'] = 'La vidéo doit durer 15 secondes au plus.';
                }
                $d['media_kind'] = $kind;
                $d['media_url'] = $m['url'];
                $d['duration_sec'] = $dur;
            }
        }
        if (array_key_exists('caption', $j)) {
            $c = trim((string) preg_replace('/\s+/u', ' ', (string) ($j['caption'] ?? '')));
            if (mb_strlen($c) > 90) {
                $errors['caption'] = 'La légende fait 90 caractères au plus.';
            } elseif (\App\Modules\Moderation\ContentFilter::check($c) !== null) {
                $errors['caption'] = 'Cette légende ne respecte pas les règles de publication.';
            }
            $d['caption'] = $c;
        }
        if (array_key_exists('caption_style', $j)) {
            if (!in_array($j['caption_style'], self::STYLES, true)) {
                $errors['caption_style'] = 'Style : bandeau, plein ou aucun.';
            }
            $d['caption_style'] = (string) $j['caption_style'];
        }
        if (array_key_exists('cta', $j)) {
            $d['cta'] = null;
            if ($j['cta'] !== null) {
                $c = is_array($j['cta']) ? $j['cta'] : [];
                $kind = $c['kind'] ?? null;
                $label = trim((string) ($c['label'] ?? ''));
                $target = is_string($c['target_id'] ?? null) && $c['target_id'] !== '' ? $c['target_id'] : null;
                $url = is_string($c['url'] ?? null) && $c['url'] !== '' ? $c['url'] : null;
                if (!in_array($kind, self::CTA_KINDS, true)) {
                    $errors['cta'] = 'Bouton : place, deal, event ou url.';
                } elseif ($label === '' || mb_strlen($label) > 24) {
                    $errors['cta'] = 'Le texte du bouton fait de 1 à 24 caractères.';
                } elseif ($kind === 'url' && ($url === null || !str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL))) {
                    $errors['cta'] = 'Le lien doit commencer par https://.';
                } elseif ($kind === 'deal' && ($target === null || !Database::fetch('SELECT 1 FROM deals WHERE uid = :u AND business_id = :b AND deleted_at IS NULL', ['u' => $target, 'b' => $business['id']]))) {
                    $errors['cta'] = 'Choisis un de tes bons plans.';
                } elseif ($kind === 'event' && ($target === null || !Database::fetch('SELECT 1 FROM events WHERE slug = :s AND business_id = :b', ['s' => $target, 'b' => $business['id']]))) {
                    $errors['cta'] = 'Choisis un de tes événements.';
                } else {
                    if ($kind === 'place') {
                        $place = Database::fetch('SELECT app_id, uid FROM places WHERE id = :id', ['id' => $business['place_id']]);
                        $target = $place['app_id'] ?? $place['uid'] ?? null;
                    }
                    $d['cta'] = json_encode(['kind' => $kind, 'label' => $label, 'target_id' => $kind === 'url' ? null : $target, 'url' => $kind === 'url' ? $url : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        if ($current === null || array_key_exists('starts_at', $j)) {
            $start = ($j['starts_at'] ?? null) === null ? time() : strtotime((string) $j['starts_at']);
            if ($start === false) {
                $errors['starts_at'] = 'Date de diffusion invalide.';
            } elseif ($start > time() + 7 * 86400) {
                $errors['starts_at'] = 'Une story se programme 7 jours à l\'avance au plus.';
            } else {
                $d['starts_at'] = gmdate('Y-m-d H:i:s', max($start, $current === null ? time() : $start));
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $d['expires_at'] = gmdate('Y-m-d H:i:s', strtotime($d['starts_at'] . ' UTC') + 86400);

        return $d;
    }

    /** 2 (gratuit) ou 10 (partenaire) stories diffusées ou programmées le même jour, heure de Paris */
    private static function quota(array $business, string $startsAt, ?int $exceptId): void
    {
        $limit = (int) (Pro::limits($business)['stories_per_day'] ?? 2);
        $tz = new \DateTimeZone('Europe/Paris');
        $day = (new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC')))->setTimezone($tz)->setTime(0, 0);
        $from = $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = $day->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $n = (int) Database::fetch('SELECT COUNT(*) AS n FROM pro_stories WHERE business_id = :b AND starts_at >= :f AND starts_at < :t AND id <> :x',
            ['b' => $business['id'], 'f' => $from, 't' => $to, 'x' => $exceptId ?? 0])['n'];
        if ($n >= $limit) {
            throw new HttpException(409, 'story_daily_limit', $business['plan'] === 'partenaire'
                ? "Tu as atteint la limite de {$limit} stories pour ce jour."
                : "Ta formule Gratuit permet {$limit} stories par jour. Passe Partenaire pour en publier jusqu'à 10.",
                ['reason' => 'story_daily_limit', 'limit' => $limit, 'plan' => $business['plan']]);
        }
    }

    private function find(Request $request): array
    {
        return Database::fetch('SELECT * FROM pro_stories WHERE uid = :u AND business_id = :b', ['u' => strtolower((string) $request->param('id')), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Story introuvable.');
    }

    private function row(int $id): array
    {
        return Database::fetch('SELECT * FROM pro_stories WHERE id = :id', ['id' => $id]);
    }
}
