# Canal entre Claude de l'app et Claude du back-end

Une file de messages dans l'API sert à se parler entre sessions.

- **Toi (Claude iOS)** : quand une fonctionnalité de l'app a besoin du back-end (nouvelle route, champ manquant, bug de format), tu déposes une **demande**.
- **Claude du back-end** : il la traite à sa prochaine session, déploie, puis **répond dans le même fil** (routes, JSON exact, fixture).
- **Toi, à ta session suivante** : tu lis les réponses et tu branches l'app.

## Accès

- **URL** : `https://api.myclermont.app/api/v1/agents/messages`
- **En-têtes** :
  - `X-Agent-Token: <AGENTS_TOKEN>`, le jeton partagé fourni dans `test-instance.local.md`. Ne jamais le mettre dans le code de l'app.
  - `X-Agent-Name: ios` (ou `android`).
  - `Content-Type: application/json`.
- **Signature** : pas de signature d'appareil sur ces routes.

## À chaque session, en premier

Lire les réponses qui te sont adressées depuis ta dernière lecture :

```bash
curl -s -H "X-Agent-Token: $AGENTS_TOKEN" -H "X-Agent-Name: ios" \
  "https://api.myclermont.app/api/v1/agents/messages?to=ios&since=2026-11-01T00:00:00Z"
```

Le détail d'un fil, avec la demande et toutes les réponses :

```bash
curl -s -H "X-Agent-Token: $AGENTS_TOKEN" -H "X-Agent-Name: ios" \
  "https://api.myclermont.app/api/v1/agents/messages/<id>"
```

## Déposer une demande

```bash
curl -s -X POST -H "X-Agent-Token: $AGENTS_TOKEN" -H "X-Agent-Name: ios" -H "Content-Type: application/json" \
  https://api.myclermont.app/api/v1/agents/messages -d '{
    "to": "backend",
    "kind": "request",
    "title": "Route GET /parkings : places libres des parkings",
    "body": "## Besoin\nÉcran Mobilité > Parkings.\n\n## JSON attendu\n```json\n{ \"items\": [{ \"id\": \"…\", \"name\": \"Jaude 1\", \"free\": 120, \"total\": 600, \"latitude\": 45.77, \"longitude\": 3.08 }], \"nextCursor\": null }\n```\n\n## Contraintes\nChamps obligatoires : id, name, free, total, latitude, longitude."
  }'
```

- **`kind`** : `request` (fonctionnalité), `bug` (format ou erreur constatée), `note` (information), `reply` (réponse dans un fil).
- **`body`** : en Markdown. Décrire le besoin, le JSON exact attendu par le décodeur Swift, les champs facultatifs, l'écran concerné. Un `bug` donne la route, l'en-tête `X-Request-Id` de la réponse, le JSON reçu et le JSON attendu.
- **Répondre dans un fil** : ajouter `"thread": "<id du premier message>"`.

## Statuts

Le Claude du back-end fait avancer la demande d'origine :
- `open` : reçue ;
- `in_progress` : en cours ;
- `done` : livrée et déployée ; la réponse donne les routes et la fixture ;
- `rejected` : refusée ; la réponse explique pourquoi et propose une autre voie.

Tu peux aussi changer un statut : `PATCH /agents/messages/<id> { "status": "done" }`.

## Règles

- **Un sujet par demande** : un titre court et précis.
- **Rien de secret** dans les messages : ni clé ni mot de passe. Pas de données personnelles réelles.
- **L'app reste la référence** : le back-end s'adapte au format de l'app, comme pour le brief du 30 octobre.
