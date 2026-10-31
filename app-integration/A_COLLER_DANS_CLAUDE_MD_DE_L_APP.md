<!--
À copier tel quel dans le fichier CLAUDE.md à la racine du projet de l'app iOS (et Android).
Ces règles s'appliquent alors à chaque session, de manière définitive.
-->

## Back-end My Clermont : canal avec le Claude du back-end (OBLIGATOIRE)

Le back-end (PHP/MySQL, `https://api.myclermont.app/api/v1`) est développé par un autre Claude. Vous communiquez **uniquement** par le canal agents. Mode d'emploi complet : `app-integration/CANAL_AGENTS.md`. Le jeton `AGENTS_TOKEN` est dans `app-integration/test-instance.local.md` ; il ne doit jamais être commité ni compilé dans l'app.

En-têtes de **chaque** appel au canal :

```
X-Agent-Token: $AGENTS_TOKEN
X-Agent-Name: ios
X-Agent-Version: <version (build) de l'app, ex. 0.9.2 (18)>
X-Agent-Branch: <branche git courante>
X-Agent-Commit: <sha court du dernier commit>
Content-Type: application/json
```

### 1. Au début de CHAQUE session, avant tout autre travail

0. **Mettre à jour le dossier `app-integration/` tout seul** (consignes, routes, fixtures). Ne jamais demander au propriétaire de le renvoyer à la main :
   ```bash
   V=$(curl -s -H "X-Agent-Token: $AGENTS_TOKEN" -H "X-Agent-Name: ios" https://api.myclermont.app/api/v1/agents/status | python3 -c 'import sys,json; print(json.load(sys.stdin)["bundle"]["version"])')
   if [ "$V" != "$(cat app-integration/VERSION 2>/dev/null)" ]; then
     curl -sf -H "X-Agent-Token: $AGENTS_TOKEN" -H "X-Agent-Name: ios" -o /tmp/app-integration.zip https://api.myclermont.app/api/v1/agents/bundle \
       && unzip -oq /tmp/app-integration.zip -d . && echo "app-integration mis à jour ($V)"
   fi
   ```
   - L'archive ne contient jamais `test-instance.local.md` (secrets) : le fichier local est conservé tel quel.
   - Si ce fichier (`A_COLLER_DANS_CLAUDE_MD_DE_L_APP.md`) a changé, recopier son contenu dans le `CLAUDE.md` de l'app.
   - Relire ensuite `app-integration/CLAUDE.md` et les fixtures modifiées.
1. Lire l'état des assistants (dernière connexion du back-end, son dernier compte rendu) : `GET /agents/status`.
2. Lire les messages qui te sont adressés : `GET /agents/messages?to=ios` (les 200 derniers) ; ouvrir le fil complet de chaque réponse `done` non encore intégrée : `GET /agents/messages/<id>`.
3. Lire les comptes rendus du back-end depuis ta dernière session : `GET /agents/messages?from=backend&kind=report&since=<date de ta dernière session>`.
4. Intégrer dans l'app ce que le back-end a livré (routes, JSON, fixtures dans `app-integration/fixtures/`), puis passer le fil à `done` côté app si besoin.

### 2. Pendant la session

- Une fonctionnalité a besoin du back-end (route, champ, filtre, notification) : **ne pas contourner dans l'app**. Déposer une demande `kind: "request"` à `to: "backend"`, avec l'écran concerné, le JSON exact attendu par le décodeur Swift, les champs facultatifs, les erreurs à gérer.
- Une réponse de l'API ne se décode pas ou se comporte mal : déposer `kind: "bug"`, avec la route, l'en-tête `X-Request-Id`, le JSON reçu et le JSON attendu.
- Une décision ou un changement d'app qui concerne le back-end (nouvelle version minimale, nouveau flux, champ qui n'est plus utilisé) : `kind: "note"`.

### 3. À la fin de CHAQUE session (même courte, même sans demande)

Déposer un compte rendu `kind: "report"`, `to: "backend"`, titre `Session iOS du <date> — <résumé en une ligne>`, corps en Markdown :

```markdown
## Fait
- …(fonctionnalités, écrans, routes branchées)

## Routes du back utilisées / modifiées
- GET /api/v1/… : branchée sur l'écran …

## État de l'app
- Version : 0.9.2 (18) · branche : … · commit : …
- Modules activés côté app : événements, comptes, …
- Prochaine sortie prévue : …

## En attente du back-end
- <id de la demande> — <titre> (statut)

## Questions / points d'attention
- …
```

Ne jamais mettre de secret (clés, mots de passe, jetons) ni de données personnelles réelles dans un message.

### Rappels

- L'app est la référence : le back-end s'adapte au format de l'app.
- Contrat JSON, signature des requêtes, erreurs : `app-integration/CLAUDE.md`.
