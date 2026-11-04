<?php

declare(strict_types=1);

namespace App\Docs;

use App\Core\Config;
use App\Core\Router;
use App\Support\AppSchemas;

/**
 * Documentation OpenAPI 3.1 générée à partir du code (demande iOS 65abb5dc), jamais écrite à la main :
 *   - routes, droits et limites de débit : le routeur (routes/api.php + modules) et leurs middlewares ;
 *   - résumé : première ligne du docblock de la méthode du contrôleur ;
 *   - corps de requête : règles de Validator::validate lues dans la méthode ;
 *   - codes d'erreur : HttpException / ValidationException lancées par la méthode et ses aides ;
 *   - schémas nommés : AppSchemas (vérifiés par schema:check) et fixtures de app-integration (réponses réelles des tests) ;
 *   - exemples : fixtures (valeurs fictives : camille.r, *.example).
 * Servie sur /docs (Swagger UI) et /api/v1/openapi.json : routes de l'app en public, tout (pro, admin, agents) pour une session du back-office.
 */
final class OpenApi
{
    public const VERSION = '1.4.0';

    /** Journal des changements (info.version) */
    public const CHANGELOG = [
        '1.4.0 (03/11/2026)' => 'Validation des inscriptions commerçant (/admin/merchants, 403 not_verified), avis de la team (/admin/team-reviews, teamReview), session /admin du dashboard.',
        '1.3.0 (03/11/2026)' => 'Photos des visiteurs (/visitor-photos, /pro/visitor-photos), fiche /places/{id}/sheet au format Place, notifications (/me/notifications, préférences, j\'aime sur les commentaires).',
        '1.2.0 (03/11/2026)' => 'Clermont IA : recherche de proximité OpenStreetMap, champ conversation (questions de suite).',
        '1.1.0 (02/11/2026)' => 'Pseudos, mentions, stories des établissements, limite /pro à 600 requêtes par minute.',
    ];

    /** Schémas nommés tirés des fixtures : nom => [fichier (relatif à app-integration), chemin dans le JSON] */
    private const FIXTURE_SCHEMAS = [
        'AppNotice' => ['fixtures/me-notifications.json', 'items.0'],
        'NotificationPreferences' => ['fixtures/notification-preferences.json', ''],
        'VisitorPhotoApp' => ['fixtures/visitor-photos.json', 'items.0'],
        'PlacePhoto' => ['fixtures/place-photos.json', 'items.0'],
        'PlaceRating' => ['fixtures/place-rating.json', ''],
        'MyVisitorPhoto' => ['fixtures/me-visitor-photos.json', 'items.0'],
        'VisitorPhoto' => ['pro/fixtures/get-visitor-photos.json', 'items.0'],
        'PlaceSheet' => ['fixtures/place-sheet.json', ''],
        'TeamReview' => ['fixtures/place-sheet-team-review.json', 'teamReview'],
        'RemoteStoryGroup' => ['fixtures/stories.json', 'items.0'],
        'UserSearchResult' => ['fixtures/users-search.json', 'items.0'],
        'HandleAvailability' => ['fixtures/handle-availability.json', ''],
        'Me' => ['fixtures/me.json', ''],
        'Meta' => ['fixtures/meta.json', ''],
        'GameScore' => ['fixtures/games-score-201.json', ''],
        'UploadTicket' => ['fixtures/upload-ticket.json', ''],
        'UploadConfirm' => ['fixtures/upload-confirm.json', ''],
        'ProSession' => ['pro/fixtures/post-auth-login.json', ''],
        'Merchant' => ['pro/fixtures/get-me.json', ''],
        'Business' => ['pro/fixtures/post-auth-signup.json', 'business'],
        'Place' => ['pro/fixtures/get-place.json', ''],
        'ProDeal' => ['pro/fixtures/post-deals.json', ''],
        'ProEvent' => ['pro/fixtures/post-events.json', ''],
        'ProStory' => ['pro/fixtures/post-stories.json', ''],
        'ProThread' => ['pro/fixtures/patch-threads-id.json', ''],
        'ProReview' => ['pro/fixtures/get-reviews.json', 'items.0'],
        'ProStats' => ['pro/fixtures/get-stats.json', ''],
        'ProJob' => ['pro/fixtures/post-jobs.json', ''],
        'ProService' => ['pro/fixtures/post-services.json', ''],
        'ProHousing' => ['pro/fixtures/post-housings.json', ''],
        'Partnership' => ['pro/fixtures/get-partnership.json', ''],
        'MerchantApplication' => ['pro/fixtures/get-admin-merchants.json', 'items.0'],
    ];

    /** Schémas de AppSchemas : nom => méthode */
    private const APP_SCHEMAS = [
        'ClermontEvent' => 'event', 'UserProfile' => 'userProfile', 'AuthSession' => 'session', 'MapSpot' => 'mapSpot', 'AssistantReply' => 'assistantAnswer',
        'FuelStation' => 'fuelStation', 'TrafficIncident' => 'trafficIncident', 'JobOffer' => 'job', 'HousingListing' => 'housing', 'Deal' => 'deal',
        'ServiceProvider' => 'service', 'AgendaEvent' => 'agenda', 'CommunityPost' => 'post', 'CommunityComment' => 'comment', 'Classified' => 'classified',
    ];

    /** Réponses par route (« MÉTHODE /chemin » sans /api/v1) : [schéma, liste ?, fixture d'exemple] */
    private const RESPONSES = [
        'GET /events' => ['ClermontEvent', true, 'fixtures/events.json'], 'GET /events/{id}' => ['ClermontEvent', false, 'fixtures/event.json'],
        'GET /events/agenda' => ['AgendaEvent', true, 'fixtures/agenda.json'], 'GET /places' => ['MapSpot', true, 'fixtures/places.json'],
        'GET /jobs' => ['JobOffer', true, 'fixtures/jobs.json'], 'GET /housing' => ['HousingListing', true, 'fixtures/housing.json'],
        'GET /deals' => ['Deal', true, 'fixtures/deals.json'], 'GET /services' => ['ServiceProvider', true, 'fixtures/services.json'],
        'GET /community/posts' => ['CommunityPost', true, 'fixtures/community-posts.json'], 'POST /community/posts' => ['CommunityPost', false, 'fixtures/community-post-created.json'],
        'GET /community/posts/{id}/comments' => ['CommunityComment', true, 'fixtures/comments.json'], 'POST /community/posts/{id}/comments' => ['CommunityComment', false, 'fixtures/community-comment-created.json'],
        'GET /classifieds' => ['Classified', true, 'fixtures/classifieds.json'], 'POST /classifieds' => ['Classified', false, 'fixtures/classified-created.json'],
        'GET /fuel/stations' => ['FuelStation', true, 'fixtures/fuel-stations.json'], 'GET /traffic/incidents' => [null, false, 'fixtures/traffic-incidents.json'],
        'POST /assistant/answer' => ['AssistantReply', false, 'fixtures/assistant-nearby.json'],
        'POST /auth/register' => ['AuthSession', false, 'fixtures/session.json'], 'POST /auth/login' => ['AuthSession', false, 'fixtures/session.json'],
        'POST /auth/apple' => ['AuthSession', false, 'fixtures/social-session-new.json'], 'POST /auth/google' => ['AuthSession', false, 'fixtures/social-session-new.json'],
        'GET /me' => ['Me', false, 'fixtures/me.json'], 'PATCH /me' => ['Me', false, 'fixtures/me-handle-changed.json'], 'GET /meta' => ['Meta', false, 'fixtures/meta.json'],
        'GET /me/favorites' => [null, false, 'fixtures/me-favorites.json'], 'GET /me/notifications' => ['AppNotice', true, 'fixtures/me-notifications.json'],
        'GET /me/notification-preferences' => ['NotificationPreferences', false, 'fixtures/notification-preferences.json'],
        'PUT /me/notification-preferences' => ['NotificationPreferences', false, 'fixtures/notification-preferences.json'],
        'POST /visitor-photos' => [null, false, 'fixtures/post-visitor-photos.json'], 'GET /me/visitor-photos' => ['MyVisitorPhoto', true, 'fixtures/me-visitor-photos.json'],
        'GET /visitor-photos' => ['VisitorPhotoApp', true, 'fixtures/visitor-photos.json'], 'GET /places/{id}/photos' => ['PlacePhoto', true, 'fixtures/place-photos.json'],
        'GET /places/{id}/rating' => ['PlaceRating', false, 'fixtures/place-rating.json'], 'GET /places/{id}/sheet' => ['PlaceSheet', false, 'fixtures/place-sheet.json'],
        'GET /stories' => ['RemoteStoryGroup', true, 'fixtures/stories.json'], 'GET /users/search' => ['UserSearchResult', true, 'fixtures/users-search.json'],
        'GET /users/handle-availability' => ['HandleAvailability', false, 'fixtures/handle-availability.json'],
        'POST /games/{game}/scores' => ['GameScore', false, 'fixtures/games-score-201.json'], 'GET /games/{game}/leaderboard' => [null, false, 'fixtures/games-leaderboard.json'],
        'POST /uploads' => ['UploadTicket', false, 'fixtures/upload-ticket.json'], 'POST /uploads/{id}/confirm' => ['UploadConfirm', false, 'fixtures/upload-confirm.json'],
        'POST /deals/{id}/redeem' => [null, false, 'fixtures/deal-redemption.json'], 'GET /health' => [null, false, 'fixtures/health.json'],
        'POST /classifieds/{id}/contact' => [null, false, 'fixtures/classified-contact.json'], 'POST /jobs/{id}/apply' => [null, false, 'fixtures/job-application-created.json'],
        // Espace commerçant
        'POST /pro/auth/signup' => ['ProSession', false, 'pro/fixtures/post-auth-signup.json'], 'POST /pro/auth/login' => ['ProSession', false, 'pro/fixtures/post-auth-login.json'],
        'POST /pro/auth/refresh' => ['ProSession', false, 'pro/fixtures/post-auth-refresh.json'], 'GET /pro/me' => ['Merchant', false, 'pro/fixtures/get-me.json'],
        'GET /pro/auth/sessions' => [null, false, 'pro/fixtures/get-auth-sessions.json'], 'GET /pro/me/export' => [null, false, 'pro/fixtures/get-me-export.json'],
        'GET /pro/notifications' => [null, false, 'pro/fixtures/get-notifications.json'], 'GET /pro/place' => ['Place', false, 'pro/fixtures/get-place.json'],
        'PUT /pro/place' => ['Place', false, 'pro/fixtures/put-place.json'], 'POST /pro/place/submit' => ['Place', false, 'pro/fixtures/post-place-submit.json'],
        'GET /pro/reviews' => ['ProReview', true, 'pro/fixtures/get-reviews.json'], 'POST /pro/reviews/{id}/reply' => ['ProReview', false, 'pro/fixtures/post-reviews-id-reply.json'],
        'POST /pro/reviews/{id}/report' => ['ProReview', false, 'pro/fixtures/post-reviews-id-report.json'], 'GET /pro/threads' => ['ProThread', true, 'pro/fixtures/get-threads.json'],
        'PATCH /pro/threads/{id}' => ['ProThread', false, 'pro/fixtures/patch-threads-id.json'], 'POST /pro/threads/{id}/messages' => ['ProThread', false, 'pro/fixtures/post-threads-id-messages.json'],
        'GET /pro/deals' => ['ProDeal', true, 'pro/fixtures/get-deals.json'], 'POST /pro/deals' => ['ProDeal', false, 'pro/fixtures/post-deals.json'],
        'PATCH /pro/deals/{id}' => ['ProDeal', false, 'pro/fixtures/patch-deals-id.json'], 'GET /pro/deal-codes/{code}' => [null, false, 'pro/fixtures/get-deal-codes-myclermont-qrpayload.json'],
        'POST /pro/deal-codes/{code}/redeem' => [null, false, 'pro/fixtures/post-deal-codes-code-redeem.json'], 'GET /pro/events' => ['ProEvent', true, 'pro/fixtures/get-events.json'],
        'POST /pro/events' => ['ProEvent', false, 'pro/fixtures/post-events.json'], 'PATCH /pro/events/{id}' => ['ProEvent', false, 'pro/fixtures/patch-events-id.json'],
        'POST /pro/events/{id}/submit' => ['ProEvent', false, 'pro/fixtures/post-events-id-submit.json'], 'GET /pro/stories' => ['ProStory', true, 'pro/fixtures/get-stories.json'],
        'POST /pro/stories' => ['ProStory', false, 'pro/fixtures/post-stories.json'], 'POST /pro/jobs' => ['ProJob', false, 'pro/fixtures/post-jobs.json'],
        'PATCH /pro/jobs/{id}' => ['ProJob', false, 'pro/fixtures/patch-jobs-id.json'], 'POST /pro/services' => ['ProService', false, 'pro/fixtures/post-services.json'],
        'POST /pro/housings' => ['ProHousing', false, 'pro/fixtures/post-housings.json'], 'GET /pro/stats' => ['ProStats', false, 'pro/fixtures/get-stats.json'],
        'GET /pro/partnership' => ['Partnership', false, 'pro/fixtures/get-partnership.json'], 'POST /pro/partnership/subscribe' => [null, false, 'pro/fixtures/post-partnership-subscribe.json'],
        'POST /pro/partnership/cancel' => [null, false, 'pro/fixtures/post-partnership-cancel.json'], 'POST /pro/team/invitations' => [null, false, 'pro/fixtures/post-team-invitations.json'],
        'GET /pro/visitor-photos' => ['VisitorPhoto', true, 'pro/fixtures/get-visitor-photos.json'], 'POST /pro/visitor-photos/validate' => [null, false, 'pro/fixtures/post-visitor-photos-validate.json'],
        'GET /pro/business' => ['Business', false, null], 'PATCH /pro/business' => ['Business', false, null], 'POST /pro/business/verification/reply' => ['Business', false, null],
        // Équipe
        'GET /admin/merchants' => ['MerchantApplication', true, 'pro/fixtures/get-admin-merchants.json'], 'GET /admin/merchants/{id}' => ['MerchantApplication', false, null],
        'POST /admin/merchants/{id}/approve' => ['MerchantApplication', false, null], 'POST /admin/merchants/{id}/request-info' => ['MerchantApplication', false, null],
        'POST /admin/merchants/{id}/reject' => ['MerchantApplication', false, null], 'GET /admin/visitor-photos' => ['VisitorPhoto', true, null],
    ];

    /** Codes d'erreur communs selon les middlewares */
    private const MIDDLEWARE_ERRORS = [
        'device' => [401 => 'unauthenticated (details.reason : invalid_device, invalid_signature, expired_timestamp, replayed_nonce)'],
        'auth' => [401 => 'unauthenticated (token_expired, invalid_token)'], 'pro' => [401 => 'unauthenticated (invalid_session) · token_expired'],
        'staff' => [401 => 'unauthenticated (invalid_session)', 403 => 'forbidden (jeton commerçant ou de l\'app)'], 'role' => [403 => 'forbidden'],
        'throttle' => [429 => 'rate_limited (en-tête Retry-After)'], 'agents' => [401 => 'unauthenticated (jeton agents invalide)'],
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /** @param bool $full false = routes de l'app seulement (version publique) */
    public function build(bool $full): array
    {
        $router = new Router();
        (require $this->root . '/routes/api.php')($router, \App\Core\App::bootModules());
        $paths = [];
        foreach ($router->routes() as $r) {
            if (!str_starts_with($r['path'], '/api/v1')) {
                continue; // /v1 : mêmes routes, ancienne base d'URL
            }
            $path = substr($r['path'], 7) ?: '/';
            $tag = $this->tag($path, $r['middleware']);
            if (!$full && $tag !== 'app') {
                continue;
            }
            $method = strtolower($r['method']);
            $paths[preg_replace('#\{(\w+):[^}]+\}#', '{$1}', $path)][$method] = $this->operation($r, $path, $tag);
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API My Clermont', 'version' => self::VERSION,
                'description' => "Générée à partir du code (routes, middlewares, règles de validation, schémas vérifiés par schema:check, fixtures).\n\n"
                    . "Réponses : clés en camelCase, dates ISO 8601. Listes : `{ items, nextCursor }`. Erreurs : `{ error: { code, message, details } }` (message en français, affichable tel quel).\n\n"
                    . "Signature des requêtes de l'app : voir app-integration/SIGNATURE.md.\n\n## Changements\n"
                    . implode("\n", array_map(static fn ($v, $k) => "- **{$k}** : {$v}", self::CHANGELOG, array_keys(self::CHANGELOG)))
                    . ($full ? '' : "\n\n_Version publique : routes de l'app seulement. La version complète (espace commerçant, administration, agents) demande une session du back-office._"),
            ],
            'servers' => [['url' => rtrim((string) Config::get('app.url', 'https://api.myclermont.app'), '/') . '/api/v1']],
            'tags' => array_values(array_filter([
                ['name' => 'app', 'description' => 'Application iOS / Android (signature d\'appareil)'],
                $full ? ['name' => 'pro', 'description' => 'Espace commerçant (commercant.myclermont.app)'] : null,
                $full ? ['name' => 'admin', 'description' => 'Administration : espace /admin du dashboard (session de l\'équipe) et routes d\'administration de l\'app (rôle)'] : null,
                $full ? ['name' => 'agents', 'description' => 'Canal entre les Claude (app-integration/CANAL_AGENTS.md)'] : null,
            ])),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->security($full),
                'schemas' => $this->schemas($full),
            ],
        ];
    }

    private function tag(string $path, array $middleware): string
    {
        return match (true) {
            str_starts_with($path, '/pro') => 'pro',
            str_starts_with($path, '/agents') => 'agents',
            str_starts_with($path, '/admin') => 'admin',
            default => 'app',
        };
    }

    private function operation(array $r, string $path, string $tag): array
    {
        [$class, $methodName] = $r['handler'] + [null, null];
        $ref = null;
        try {
            $ref = new \ReflectionMethod($class, $methodName);
        } catch (\Throwable) {
        }
        $doc = $ref ? (string) $ref->getDocComment() : '';
        $summary = trim((string) preg_replace(['#^/\*\*\s*#', '#\s*\*/$#', '#\n\s*\*\s?#'], ['', '', ' '], $doc));
        $summary = $summary !== '' ? mb_substr((string) preg_split('/(?<=[.:])\s|\s{2,}/u', $summary)[0], 0, 160) : '';
        if ($summary === '' && $ref) {
            // Docblock de la classe : ligne qui décrit cette route (« POST /visitor-photos { … } → 201 … »)
            $short = (string) preg_replace(['#^/(pro|admin)#', '#\{(\w+):[^}]+\}#'], ['', '{$1}'], $path);
            foreach (explode("\n", (string) $ref->getDeclaringClass()->getDocComment()) as $line) {
                $line = trim(ltrim(trim($line), '*'));
                if (preg_match('#\b' . $r['method'] . '\b[^/]*' . preg_quote($short, '#') . '(\s|\?|$)#', $line) || preg_match('#\b' . $r['method'] . '\b[^/]*' . preg_quote($path, '#') . '(\s|\?|$)#', $line)) {
                    $summary = mb_substr($line, 0, 200);
                    break;
                }
            }
        }
        $summary = $summary !== '' ? $summary : $r['method'] . ' ' . $path;
        $source = $ref ? $this->source($ref) : '';
        $clean = (string) preg_replace('#\{(\w+):[^}]+\}#', '{$1}', $path);
        $key = $r['method'] . ' ' . $clean;

        $op = ['tags' => [$tag], 'summary' => $summary, 'operationId' => strtolower($r['method']) . str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $clean)];
        if ($ref) {
            $op['x-handler'] = (new \ReflectionClass($class))->getShortName() . '::' . $methodName;
        }
        // Sécurité
        $mw = array_map(static fn ($m) => explode(':', $m)[0], $r['middleware']);
        $sec = [];
        if (in_array('device', $mw, true)) {
            $sec = in_array('auth', $mw, true) && !in_array('auth:optional', $r['middleware'], true) ? [['deviceSignature' => [], 'bearerUser' => []]]
                : (in_array('auth:optional', $r['middleware'], true) ? [['deviceSignature' => [], 'bearerUser' => []], ['deviceSignature' => []]] : [['deviceSignature' => []]]);
        }
        if (in_array('pro', $mw, true)) {
            $sec = [['bearerPro' => []]];
        }
        if (in_array('staff', $mw, true)) {
            $sec = [['bearerStaff' => []]];
        }
        if (str_starts_with($clean, '/agents')) {
            $sec = [['agentsToken' => []]];
        }
        if ($clean === '/devices/register' || $clean === '/devices') {
            $sec = [['appKey' => []]];
        }
        $op['security'] = $sec;
        foreach ($r['middleware'] as $m) {
            if (str_starts_with($m, 'role:')) {
                $op['x-roles'] = explode(',', substr($m, 5));
            }
            if (str_starts_with($m, 'throttle:')) {
                $name = explode(',', substr($m, 9))[0];
                [$max, $window] = (array) Config::get('security.rate_limits.' . $name, [null, null]) + [null, null];
                $op['x-rate-limit'][] = ['name' => $name, 'max' => $max, 'windowSeconds' => $window, 'per' => explode(',', substr($m, 9))[1] ?? 'device'];
            }
        }
        // Paramètres
        $params = [];
        preg_match_all('#\{(\w+)#', $clean, $m);
        foreach ($m[1] as $p) {
            $params[] = ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        preg_match_all('#\$(?:q|query|request->query\(\))\[\'([a-z_]+)\'\]#', $source, $qm);
        foreach (array_unique($qm[1]) as $q) {
            $params[] = ['name' => self::camel($q), 'in' => 'query', 'required' => false, 'schema' => ['type' => in_array($q, ['limit', 'cursor', 'offset'], true) ? 'string' : 'string']];
        }
        if ($params !== []) {
            $op['parameters'] = $params;
        }
        // Corps de requête
        if (in_array($r['method'], ['POST', 'PUT', 'PATCH'], true)) {
            $body = $this->requestSchema($source);
            $op['requestBody'] = ['required' => $body['required'] !== [], 'content' => ['application/json' => ['schema' => $body['required'] === [] ? array_diff_key($body, ['required' => 1]) : $body]]];
        }
        // Réponses
        $resp = self::RESPONSES[$key] ?? null;
        $status = str_contains($source, 'Response::noContent()') && !str_contains($source, 'Response::success') ? '204'
            : (preg_match('/Response::success\([^;]*,\s*201\)/s', $source) || str_contains($source, '], 201)') ? '201' : '200');
        if ($status === '204') {
            $op['responses']['204'] = ['description' => 'Fait, sans contenu'];
        } else {
            $schema = ['type' => 'object'];
            if ($resp !== null && $resp[0] !== null) {
                $item = ['$ref' => '#/components/schemas/' . $resp[0]];
                $schema = $resp[1] ? ['type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => $item], 'nextCursor' => ['type' => ['string', 'null']]]] : $item;
            }
            $content = ['schema' => $schema];
            if ($resp !== null && $resp[2] !== null && is_file($f = $this->root . '/app-integration/' . $resp[2])) {
                $content['example'] = json_decode((string) file_get_contents($f), true);
            }
            $op['responses'][$status] = ['description' => 'Succès', 'content' => ['application/json' => $content]];
        }
        foreach ($this->errors($source, $r['middleware']) as $code => $codes) {
            $op['responses'][(string) $code] = ['description' => implode(' · ', array_unique($codes)), 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        ksort($op['responses']);

        return $op;
    }

    /** Source de la méthode, plus celle des méthodes privées de la même classe qu'elle appelle (validate, find…) */
    private function source(\ReflectionMethod $ref): string
    {
        $file = file((string) $ref->getFileName());
        $src = implode('', array_slice($file ?: [], $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        preg_match_all('#(?:self::|\$this->)(\w+)\(#', $src, $m);
        foreach (array_unique($m[1]) as $name) {
            if ($name === $ref->getName() || !$ref->getDeclaringClass()->hasMethod($name)) {
                continue;
            }
            $h = $ref->getDeclaringClass()->getMethod($name);
            if ($h->getFileName() === $ref->getFileName()) {
                $src .= implode('', array_slice($file ?: [], $h->getStartLine() - 1, $h->getEndLine() - $h->getStartLine() + 1));
            }
        }

        // Un niveau d'appels vers d'autres classes du projet (Verification::approve, VisitorPhotos::validate…) : leurs erreurs comptent
        $uses = [];
        preg_match_all('#^use (App\\\\[\w\\\\]+?)(?: as (\w+))?;#m', implode('', $file ?: []), $um, PREG_SET_ORDER);
        foreach ($um as $u) {
            $uses[$u[2] ?? substr(strrchr('\\' . $u[1], '\\'), 1)] = $u[1];
        }
        preg_match_all('#\\\\?([A-Z][\w\\\\]*)::(\w+)\(#', $src, $cm, PREG_SET_ORDER);
        $extra = '';
        foreach ($cm as [, $cls, $fn]) {
            $fq = str_contains($cls, '\\') ? $cls : ($uses[$cls] ?? $ref->getDeclaringClass()->getNamespaceName() . '\\' . $cls);
            if (!str_starts_with(ltrim($fq, '\\'), 'App\\') || in_array($fq, ['App\\Core\\Response', 'App\\Core\\Database'], true) || !method_exists($fq, $fn)) {
                continue;
            }
            $h = new \ReflectionMethod($fq, $fn);
            $lines = file((string) $h->getFileName()) ?: [];
            $extra .= implode('', array_slice($lines, $h->getStartLine() - 1, $h->getEndLine() - $h->getStartLine() + 1));
        }

        return $src . preg_replace("#'([a-z_]+)'\s*=>\s*'(required|nullable)[^']*'#", '', $extra);
    }

    /** @return array{type: string, properties: array, required: list<string>} */
    private function requestSchema(string $source): array
    {
        $props = [];
        $required = [];
        if (preg_match_all("#'([a-z_]+)'\s*=>\s*'((?:required|nullable|string|integer|boolean|array|email|uuid|in:|min:|max:|numeric|url|date)[^']*)'#", $source, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $field, $rules]) {
                $rs = explode('|', $rules);
                $s = ['type' => in_array('integer', $rs, true) ? 'integer' : (in_array('boolean', $rs, true) ? 'boolean' : (in_array('array', $rs, true) ? 'array' : (in_array('numeric', $rs, true) ? 'number' : 'string')))];
                foreach ($rs as $rule) {
                    if (str_starts_with($rule, 'in:')) {
                        $s['enum'] = explode(',', substr($rule, 3));
                    } elseif (str_starts_with($rule, 'max:')) {
                        $s[$s['type'] === 'string' ? 'maxLength' : ($s['type'] === 'array' ? 'maxItems' : 'maximum')] = (int) substr($rule, 4);
                    } elseif (str_starts_with($rule, 'min:')) {
                        $s[$s['type'] === 'string' ? 'minLength' : ($s['type'] === 'array' ? 'minItems' : 'minimum')] = (int) substr($rule, 4);
                    } elseif ($rule === 'email' || $rule === 'uuid' || $rule === 'url' || $rule === 'date') {
                        $s['format'] = $rule === 'url' ? 'uri' : ($rule === 'date' ? 'date-time' : $rule);
                    } elseif ($rule === 'nullable') {
                        $s['type'] = [$s['type'], 'null'];
                    }
                }
                $props[self::camel($field)] = $s;
                if (in_array('required', $rs, true)) {
                    $required[] = self::camel($field);
                }
            }
        }
        // Champs lus directement dans le JSON ($j['…'], $json['…'], ->json()['…'])
        preg_match_all("#(?:\\\$j|\\\$json|->json\(\))\['([a-z_]+)'\]#", $source, $jm);
        foreach (array_unique($jm[1]) as $f) {
            $props[self::camel($f)] ??= ['description' => 'Voir la validation de la route'];
        }

        return ['type' => 'object', 'properties' => (object) $props, 'required' => array_values(array_unique($required))];
    }

    /** @return array<int, list<string>> statut => codes */
    private function errors(string $source, array $middleware): array
    {
        $out = [];
        if (preg_match('/ValidationException|Validator::validate/', $source)) {
            $out[400][] = 'validation_failed (details.fields)';
        }
        preg_match_all("#new HttpException\((\d{3}),\s*'([a-z_]+)'#", $source, $m, PREG_SET_ORDER);
        foreach ($m as [, $status, $code]) {
            $out[(int) $status][] = $code;
        }
        preg_match_all("#HttpException::(unauthorized|forbidden|notFound)\('([a-z_]+)'#", $source, $m, PREG_SET_ORDER);
        foreach ($m as [, $fn, $code]) {
            $out[['unauthorized' => 401, 'forbidden' => 403, 'notFound' => 404][$fn]][] = $code;
        }
        if (str_contains($source, 'Verification::require')) {
            $out[403][] = 'not_verified (details.verification)';
        }
        if (str_contains($source, 'Pro::requireRole')) {
            $out[403][] = 'forbidden (details.reason : role)';
        }
        foreach ($middleware as $mw) {
            foreach (self::MIDDLEWARE_ERRORS[explode(':', $mw)[0]] ?? [] as $status => $code) {
                if ($mw !== 'auth:optional') {
                    $out[$status][] = $code;
                }
            }
        }
        ksort($out);

        return $out;
    }

    private function security(bool $full): array
    {
        $s = [
            'deviceSignature' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Signature',
                'description' => 'Signature HMAC de l\'appareil : en-têtes X-Device-Id, X-Device-Token, X-Timestamp, X-Nonce et X-Signature (voir app-integration/SIGNATURE.md). « Try it out » désactivé : la production refuse les requêtes non signées.'],
            'appKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-App-Key', 'description' => 'Clé de l\'application, seulement pour l\'enregistrement d\'un appareil.'],
            'bearerUser' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Compte de l\'app (typ access, lié à l\'appareil). Rafraîchi par POST /auth/refresh.'],
        ];
        if ($full) {
            $s += [
                'bearerPro' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Session commerçant (typ pro, 15 min) ; renouvelée par POST /pro/auth/refresh avec le cookie mc_pro_rt.'],
                'bearerStaff' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Équipe MyClermont (typ staff, 8 h) : POST /admin/auth/login avec un compte du back-office (+ code 2FA).'],
                'agentsToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Agent-Token', 'description' => 'Jeton du canal agents, avec X-Agent-Name (backend, ios, android, dashboard).'],
            ];
        }

        return $s;
    }

    private function schemas(bool $full): array
    {
        $out = ['Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => [
            'code' => ['type' => 'string', 'description' => 'Code stable (validation_failed, unauthenticated, forbidden, not_found, conflict, rate_limited, handle_taken, not_verified, already_decided…)'],
            'message' => ['type' => 'string', 'description' => 'Message en français, affichable tel quel'],
            'details' => ['type' => ['object', 'null'], 'description' => 'reason, fields (erreurs par champ), suggestions…'],
        ]]]]];
        foreach (self::APP_SCHEMAS as $name => $method) {
            $out[$name] = self::fromDsl(AppSchemas::$method());
        }
        foreach (self::FIXTURE_SCHEMAS as $name => [$file, $pointer]) {
            if (!$full && (str_starts_with($file, 'pro/') || $name === 'VisitorPhoto')) {
                continue;
            }
            $data = json_decode((string) @file_get_contents($this->root . '/app-integration/' . $file), true);
            foreach ($pointer === '' ? [] : explode('.', $pointer) as $k) {
                $data = is_array($data) ? ($data[$k] ?? null) : null;
            }
            if (is_array($data)) {
                $out[$name] = self::infer($data) + ['x-source' => 'app-integration/' . $file];
            }
        }
        // Notifications push (webhook côté app) : charge APNs et types de notifications
        $out['NoticeKind'] = ['type' => 'string', 'enum' => array_keys(\App\Modules\Notifications\Notices::FAMILIES),
            'description' => 'Famille de chaque type : ' . implode(', ', array_map(static fn ($k, $f) => "{$k} → " . ($f ?? 'aucune'), array_keys(\App\Modules\Notifications\Notices::FAMILIES), \App\Modules\Notifications\Notices::FAMILIES))];
        $out['ApnsPayload'] = ['type' => 'object', 'description' => 'Push APNs envoyé à l\'app (apns-collapse-id = noticeId, thread-id = famille).', 'properties' => [
            'aps' => ['type' => 'object', 'properties' => ['alert' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string']]],
                'sound' => ['type' => 'string'], 'badge' => ['type' => 'integer', 'description' => 'Notifications non lues'], 'thread-id' => ['type' => 'string']]],
            'noticeId' => ['type' => 'string', 'description' => 'ntf_<uuid>, même id que GET /me/notifications'], 'kind' => ['$ref' => '#/components/schemas/NoticeKind'],
            'target' => ['type' => ['object', 'null'], 'description' => 'Même objet que AppNotice.target'],
        ], 'example' => ['aps' => ['alert' => ['title' => '❤️ camille.r aime ta publication', 'body' => '« Le marché Saint-Joseph ce matin… »'], 'sound' => 'default', 'badge' => 3, 'thread-id' => 'community'],
                         'noticeId' => 'ntf_2b6f0c1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f', 'kind' => 'post_liked', 'target' => ['type' => 'post', 'id' => '8f0c2a1b-3c4d-4e5f-9a0b-1c2d3e4f5a6b']]];

        return $out;
    }

    /** Règles AppSchemas → JSON Schema */
    public static function fromDsl(mixed $rule): array
    {
        if (is_array($rule)) {
            $props = [];
            $required = [];
            foreach ($rule as $k => $v) {
                $optional = is_string($v) && str_starts_with($v, '?');
                $props[$k] = self::fromDsl(is_string($v) ? ltrim($v, '?') : $v);
                if ($optional) {
                    $props[$k]['type'] = array_values(array_unique([...(array) ($props[$k]['type'] ?? 'string'), 'null']));
                } else {
                    $required[] = $k;
                }
            }

            return ['type' => 'object', 'properties' => (object) $props, 'required' => $required];
        }
        $rule = (string) $rule;

        return match (true) {
            str_starts_with($rule, 'list:') => ['type' => 'array', 'items' => self::fromDsl(json_decode(substr($rule, 5), true) ?? substr($rule, 5))],
            str_starts_with($rule, 'enum:') => ['type' => 'string', 'enum' => explode('|', substr($rule, 5))],
            $rule === 'int' => ['type' => 'integer'], $rule === 'number' => ['type' => 'number'], $rule === 'bool' => ['type' => 'boolean'],
            $rule === 'date' => ['type' => 'string', 'format' => 'date-time'], $rule === 'url' => ['type' => 'string', 'format' => 'uri'],
            $rule === 'string[]' => ['type' => 'array', 'items' => ['type' => 'string']], $rule === 'int[]' => ['type' => 'array', 'items' => ['type' => 'integer']],
            $rule === 'array' => ['type' => 'array'], $rule === 'icon' => ['type' => 'string', 'description' => 'Nom SF Symbols (liste blanche AppSchemas::ICONS)'],
            default => ['type' => 'string'],
        };
    }

    /** Schéma déduit d'une réponse réelle (fixture) */
    public static function infer(mixed $v): array
    {
        if (is_array($v) && array_is_list($v)) {
            return ['type' => 'array', 'items' => $v === [] ? new \stdClass() : self::infer($v[0])];
        }
        if (is_array($v)) {
            return ['type' => 'object', 'properties' => (object) array_map([self::class, 'infer'], $v)];
        }

        return match (true) {
            $v === null => ['type' => ['string', 'null']],
            is_bool($v) => ['type' => 'boolean'], is_int($v) => ['type' => 'integer'], is_float($v) => ['type' => 'number'],
            is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v) === 1 => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    private static function camel(string $k): string
    {
        return lcfirst(str_replace('_', '', ucwords($k, '_')));
    }
}
