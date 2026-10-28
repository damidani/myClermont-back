# Architecture

## Principes

1. **Zéro dépendance** : pas de framework. Le code chargé par requête se limite à ce qui est utilisé, et tout est préchargé en mémoire par OPcache. Moins de code = moins de surface d'attaque, moins de mises à jour de sécurité à suivre.
2. **Couches simples** : `Router → Middlewares → Controller → Repository → MySQL`. Pas d'ORM : SQL écrit à la main, explicite et indexé.
3. **Rejeter tôt et à moindre coût** : les contrôles de sécurité sont ordonnés du moins cher (format d'en-tête) au plus cher (base de données).
4. **Sans état côté PHP** : aucune session PHP. Tout l'état partagé est en base ou dans le cache (APCu / MySQL).

## Modules

Les fonctionnalités vivent dans `src/Modules/<Nom>/` et communiquent par événements : voir [MODULES.md](MODULES.md). Le noyau (`src/Core`, `src/Http`, `src/Security`) ne contient que l'infrastructure : routage, signature, appareils, jetons.

## Cycle de vie d'une requête

```
Nginx ──► public/index.php
            │
            ├─ App::boot()          charge la config (1 fichier PHP en cache OPcache en prod)
            ├─ Request::fromGlobals()  en-têtes, corps (limité à 1 Mo), IP réelle
            ├─ Router::match()      table de hachage pour les routes statiques, regex sinon
            │
            ├─ Middlewares (dans l'ordre déclaré dans routes/api.php)
            │    ├─ device          vérifie la signature de l'appareil       → attributes['device']
            │    ├─ throttle:device compteur en mémoire                       → X-RateLimit-*
            │    └─ auth            vérifie le JWT, lié à l'appareil         → attributes['user_id']
            │
            ├─ Controller           valide l'entrée (Validator), appelle les repositories
            │
            └─ Response             enveloppe JSON + en-têtes de sécurité, envoi
```

Toute exception est interceptée dans `App::handle()` :
- `HttpException` → réponse JSON avec son code HTTP et son `error.code` ;
- toute autre exception → **500** `server_error` générique, détail journalisé dans `storage/logs/` (affiché uniquement si `APP_DEBUG=true`).

## Pourquoi c'est rapide

| Choix | Gain |
|---|---|
| OPcache + `opcache.preload` + `validate_timestamps=0` | Le code PHP n'est jamais relu ni recompilé |
| `config:cache` | Pas de lecture du `.env` ni de 3 fichiers de config : 1 tableau PHP en mémoire partagée |
| Routes statiques en table de hachage | Résolution O(1) |
| APCu pour nonces, rate-limit, cache appareils | ~1 µs par opération, aucun aller-retour réseau |
| Connexion PDO persistante | Pas de handshake TCP/auth MySQL à chaque requête |
| Connexion paresseuse | `/health` et les requêtes rejetées n'ouvrent pas de connexion |
| Requêtes préparées émulées | 1 aller-retour au lieu de 2 par requête SQL |
| `last_seen_at` mis à jour au plus toutes les 5 min | Pas d'écriture SQL sur les requêtes de lecture |
| JWT autoporté | L'authentification utilisateur ne touche pas la base |
| PHP-FPM `pm=static` | Pas de fork de worker sous charge |

Coût d'une requête 👤 typique avec APCu chaud : **0 requête SQL** pour la sécurité ; seules les requêtes métier du contrôleur atteignent MySQL.

### Montée en charge horizontale

APCu est local à chaque serveur. Avec plusieurs serveurs PHP derrière un load balancer :

- **nonces** : un rejeu pourrait passer sur un autre serveur → utiliser un store partagé ;
- **rate-limit** : les compteurs seraient divisés par le nombre de serveurs.

Solution : écrire un `RedisStore` implémentant `App\Cache\CacheStore` (4 méthodes : `add` = `SET NX EX`, `increment` = `INCR` + `EXPIRE`, `get`, `set`, `delete`) et l'ajouter dans `Cache::store()`. En attendant, `CACHE_DRIVER=mysql` fonctionne en cluster (plus lent, mais correct).

## Ajouter un endpoint

Exemple : liste des événements, `GET /v1/events?page=1`.

**1. Migration** — `database/migrations/002_events.sql`

```sql
CREATE TABLE events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid        CHAR(36) NOT NULL UNIQUE,
    title      VARCHAR(200) NOT NULL,
    starts_at  DATETIME NOT NULL,
    KEY idx_events_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

puis `php bin/console migrate`.

**2. Repository** — `src/Repositories/EventRepository.php`

```php
final class EventRepository
{
    public function upcoming(int $limit, int $offset): array
    {
        return Database::fetchAll(
            'SELECT uid, title, starts_at FROM events WHERE starts_at >= :now
             ORDER BY starts_at LIMIT :limit OFFSET :offset',
            ['now' => gmdate('Y-m-d H:i:s'), 'limit' => $limit, 'offset' => $offset],
        );
    }
}
```

**3. Contrôleur** — `src/Http/Controllers/EventController.php`

```php
final class EventController
{
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->query()['page'] ?? 1));
        $events = (new EventRepository())->upcoming(20, ($page - 1) * 20);

        return Response::success($events, 200, ['page' => $page]);
    }
}
```

**4. Route** — dans la méthode `routes()` du module concerné (voir [MODULES.md](MODULES.md#créer-un-module)) :

```php
$r->get('/events', [EventController::class, 'index']);
```

**5. Documentation** — ajouter l'endpoint dans `docs/API.md` et `docs/openapi.yaml`.

En production, après déploiement : `composer dump-autoload -o --classmap-authoritative` puis `systemctl reload php8.3-fpm` (OPcache ne relit pas les fichiers).

### Règles à respecter

- **Toujours** des requêtes préparées avec paramètres — jamais de variable concaténée dans du SQL. Les seuls noms de colonnes dynamiques autorisés passent par une liste blanche (cf. `UPDATABLE` dans les repositories).
- **Toujours** valider l'entrée avec `Validator::validate()` : seuls les champs déclarés sont retournés (pas d'affectation de masse).
- **Jamais** d'ID interne dans les réponses : exposer `uid`.
- **Jamais** de secret dans une réponse ou un log (méthodes `present()` des repositories).
- Lever `HttpException` avec un `errorCode` stable et le documenter dans `docs/API.md`.
- Paginer toute liste (`LIMIT` toujours présent).

## Base de données

| Table | Rôle |
|---|---|
| `users` | Comptes. `uid` public, e-mail et pseudo uniques, Argon2id, bio, quartier, points, `premium_until`, parrainage |
| `neighborhoods` | Quartiers de Clermont-Ferrand |
| `user_events` | Actions déjà récompensées (idempotence de la gamification) |
| `user_stats` | Compteurs par utilisateur (favoris, filleuls…) |
| `user_badges` | Badges obtenus (catalogue dans `config/gamification.php`) |
| `point_transactions` | Historique des points |
| `favorites` | Favoris génériques `(user_id, type, target_id)` |
| `devices` | Installations d'app. `token_hash` (SHA-256), `secret_encrypted` (libsodium) |
| `refresh_tokens` | Sessions utilisateur par appareil, à usage unique, `replaced_by` pour la traçabilité |
| `cache_entries` | Repli du cache si APCu indisponible (nonces, compteurs) |
| `migrations` | Migrations déjà exécutées |

Toutes les dates sont stockées en **UTC** (`SET time_zone = '+00:00'` à la connexion, et dates calculées en PHP avec `gmdate()`).
