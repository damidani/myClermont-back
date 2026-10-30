# Routes de l’API

Généré depuis le routeur. Toutes les routes `/v1/…` existent aussi sous `/api/v1/…`.

Accès : **signé** = signature appareil seule · **compte optionnel** = Bearer facultatif · **compte** = Bearer obligatoire · **rôle X** = compte avec ce rôle · **public** = ni signature ni compte.

| Méthode | Chemin | Accès | Limite |
|---|---|---|---|
| GET | `/api/trafic` | public | public |
| GET | `/evenements.json` | public | public |
| GET | `/photos/manifest.json` | public | public |
| POST | `/v1/admin/push` | rôle admin |  |
| GET | `/v1/admin/reports` | rôle moderator |  |
| POST | `/v1/admin/reports/{id}/resolve` | rôle moderator |  |
| POST | `/v1/admin/users/{id}/ban` | rôle moderator |  |
| PATCH | `/v1/admin/users/{id}/role` | rôle admin |  |
| GET | `/v1/admin/{resource}` | rôle moderator |  |
| POST | `/v1/admin/{resource}` | rôle moderator |  |
| DELETE | `/v1/admin/{resource}/{id}` | rôle moderator |  |
| GET | `/v1/admin/{resource}/{id}` | rôle moderator |  |
| PATCH | `/v1/admin/{resource}/{id}` | rôle moderator |  |
| POST | `/v1/assistant/answer` | compte optionnel | ai |
| POST | `/v1/auth/apple` | signé | login |
| POST | `/v1/auth/email/start` | signé | login |
| POST | `/v1/auth/email/verify` | signé | login |
| POST | `/v1/auth/google` | signé | login |
| POST | `/v1/auth/login` | signé | login |
| POST | `/v1/auth/logout` | compte optionnel |  |
| POST | `/v1/auth/refresh` | signé | login |
| POST | `/v1/auth/register` | signé | login |
| GET | `/v1/badges` | compte |  |
| GET | `/v1/classifieds` | compte optionnel |  |
| POST | `/v1/classifieds` | compte | listing |
| GET | `/v1/classifieds/filters` | compte optionnel |  |
| DELETE | `/v1/classifieds/{id}` | compte |  |
| GET | `/v1/classifieds/{id}` | compte optionnel |  |
| PATCH | `/v1/classifieds/{id}` | compte |  |
| POST | `/v1/classifieds/{id}/contact` | compte | contact |
| POST | `/v1/classifieds/{id}/messages` | compte | contact |
| POST | `/v1/classifieds/{id}/report` | compte |  |
| PATCH | `/v1/classifieds/{id}/status` | compte |  |
| DELETE | `/v1/community/comments/{id}` | compte |  |
| GET | `/v1/community/posts` | compte optionnel |  |
| POST | `/v1/community/posts` | compte | post |
| DELETE | `/v1/community/posts/{id}` | compte |  |
| GET | `/v1/community/posts/{id}` | compte optionnel |  |
| GET | `/v1/community/posts/{id}/comments` | compte optionnel |  |
| POST | `/v1/community/posts/{id}/comments` | compte | comment |
| DELETE | `/v1/community/posts/{id}/like` | compte |  |
| POST | `/v1/community/posts/{id}/like` | compte |  |
| PUT | `/v1/community/posts/{id}/like` | compte |  |
| POST | `/v1/community/posts/{id}/report` | compte |  |
| POST | `/v1/community/posts/{id}/vote` | compte |  |
| PUT | `/v1/community/posts/{id}/vote` | compte |  |
| GET | `/v1/conversations` | compte |  |
| GET | `/v1/conversations/unread-count` | compte |  |
| DELETE | `/v1/conversations/{id}` | compte |  |
| GET | `/v1/conversations/{id}` | compte |  |
| GET | `/v1/conversations/{id}/messages` | compte |  |
| POST | `/v1/conversations/{id}/messages` | compte | message |
| POST | `/v1/conversations/{id}/read` | compte |  |
| POST | `/v1/conversations/{id}/report` | compte |  |
| GET | `/v1/deals` | signé |  |
| POST | `/v1/deals/redemptions/{code}/validate` | rôle partner |  |
| GET | `/v1/deals/{id}` | signé |  |
| POST | `/v1/deals/{id}/redeem` | compte |  |
| POST | `/v1/devices` | compte optionnel |  |
| GET | `/v1/devices/me` | signé |  |
| PATCH | `/v1/devices/me` | signé |  |
| POST | `/v1/devices/register` | clé application | register |
| DELETE | `/v1/devices/{id}` | signé |  |
| PATCH | `/v1/devices/{id}` | compte optionnel |  |
| POST | `/v1/documents` | compte optionnel | upload |
| DELETE | `/v1/documents/{id}` | compte optionnel |  |
| GET | `/v1/events` | compte optionnel |  |
| POST | `/v1/events` | rôle partner/admin |  |
| GET | `/v1/events/agenda` | compte optionnel |  |
| GET | `/v1/events/categories` | compte optionnel |  |
| POST | `/v1/events/submissions` | compte | post |
| DELETE | `/v1/events/{id}` | compte |  |
| GET | `/v1/events/{id}` | compte optionnel |  |
| PATCH | `/v1/events/{id}` | compte |  |
| GET | `/v1/events/{id}/calendar.ics` | compte optionnel |  |
| DELETE | `/v1/events/{id}/participation` | compte |  |
| PUT | `/v1/events/{id}/participation` | compte |  |
| GET | `/v1/events/{id}/sheet` | compte optionnel |  |
| GET | `/v1/explore` | compte optionnel |  |
| GET | `/v1/explore/discover` | compte optionnel |  |
| GET | `/v1/explore/offers` | compte optionnel |  |
| GET | `/v1/explore/offers/{id}` | compte optionnel |  |
| POST | `/v1/explore/offers/{id}/claim` | compte |  |
| GET | `/v1/explore/search` | compte optionnel |  |
| GET | `/v1/explore/stories/{id}` | compte optionnel |  |
| POST | `/v1/explore/stories/{id}/view` | compte |  |
| GET | `/v1/explore/weather` | compte optionnel |  |
| GET | `/v1/fuel/stations` | signé |  |
| GET | `/v1/health` | public |  |
| GET | `/v1/housing` | compte optionnel |  |
| GET | `/v1/housing/filters` | compte optionnel |  |
| GET | `/v1/housing/map` | compte optionnel |  |
| GET | `/v1/housing/{id}` | compte optionnel |  |
| POST | `/v1/housing/{id}/contact` | compte optionnel | contact |
| POST | `/v1/housing/{id}/contacts` | compte optionnel | contact |
| GET | `/v1/jobs` | compte optionnel |  |
| GET | `/v1/jobs/filters` | compte optionnel |  |
| GET | `/v1/jobs/{id}` | compte optionnel |  |
| POST | `/v1/jobs/{id}/applications` | compte optionnel | contact |
| POST | `/v1/jobs/{id}/apply` | compte optionnel | contact |
| POST | `/v1/live-activities` | compte optionnel |  |
| DELETE | `/v1/me` | compte |  |
| GET | `/v1/me` | compte |  |
| PATCH | `/v1/me` | compte |  |
| DELETE | `/v1/me/blocks/{userId}` | compte |  |
| POST | `/v1/me/blocks/{userId}` | compte |  |
| GET | `/v1/me/export` | compte | contact |
| GET | `/v1/me/favorites` | compte |  |
| POST | `/v1/me/favorites/import` | compte |  |
| DELETE | `/v1/me/favorites/{targetType}/{targetId}` | compte |  |
| PUT | `/v1/me/favorites/{targetType}/{targetId}` | compte |  |
| POST | `/v1/me/reviews/import` | compte |  |
| GET | `/v1/me/subscription` | compte |  |
| POST | `/v1/media` | compte | upload |
| GET | `/v1/meta` | signé |  |
| GET | `/v1/mobility/destinations` | signé |  |
| GET | `/v1/mobility/journeys` | signé | mobility |
| GET | `/v1/mobility/places` | signé | mobility |
| GET | `/v1/mobility/traffic` | signé |  |
| GET | `/v1/neighborhoods` | signé |  |
| GET | `/v1/partners` | compte optionnel |  |
| GET | `/v1/places` | compte optionnel |  |
| GET | `/v1/places/nearby` | compte optionnel |  |
| GET | `/v1/places/pins` | compte optionnel |  |
| GET | `/v1/places/pins/stream` | compte optionnel |  |
| GET | `/v1/places/search` | compte optionnel |  |
| GET | `/v1/places/{id}` | compte optionnel |  |
| GET | `/v1/places/{id}/photos` | compte optionnel |  |
| PUT | `/v1/places/{id}/review` | compte |  |
| GET | `/v1/places/{id}/reviews` | signé |  |
| DELETE | `/v1/places/{id}/reviews/me` | compte |  |
| PUT | `/v1/places/{id}/reviews/me` | compte | post |
| GET | `/v1/places/{id}/sheet` | compte optionnel |  |
| POST | `/v1/reports` | compte | report |
| GET | `/v1/services` | compte optionnel |  |
| GET | `/v1/services/categories` | compte optionnel |  |
| GET | `/v1/services/{id}` | compte optionnel |  |
| POST | `/v1/services/{id}/quote` | compte optionnel | contact |
| POST | `/v1/services/{id}/requests` | compte optionnel | contact |
| PUT | `/v1/services/{id}/review` | compte |  |
| POST | `/v1/subscriptions/apple/verify` | compte |  |
| GET | `/v1/traffic/incidents` | signé |  |
| POST | `/v1/uploads` | compte | upload |
| PUT | `/v1/uploads/{id}/blob` | jeton `t` dans l’URL | upload |
| POST | `/v1/uploads/{id}/confirm` | compte |  |
| GET | `/v1/users/me` | compte |  |
| PATCH | `/v1/users/me` | compte |  |
| GET | `/v1/users/me/applications` | compte |  |
| GET | `/v1/users/me/classifieds` | compte |  |
| GET | `/v1/users/me/events` | compte |  |
| GET | `/v1/users/me/favorites` | compte |  |
| DELETE | `/v1/users/me/favorites/{type}/{id}` | compte |  |
| PUT | `/v1/users/me/favorites/{type}/{id}` | compte |  |
| PUT | `/v1/users/me/password` | compte | login |
| GET | `/v1/users/me/points` | compte |  |
| GET | `/v1/users/me/premium` | compte |  |
| GET | `/v1/users/username-available` | signé |  |
| DELETE | `/v1/users/{id}/block` | compte |  |
| PUT | `/v1/users/{id}/block` | compte |  |
| GET | `/v1/users/{username}` | signé |  |
| POST | `/v1/webhooks/apple/app-store` | Apple (JWS) | public |
