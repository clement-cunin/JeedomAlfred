# API REST Alfred

L'API REST permet de gérer les conversations Alfred de façon programmatique — créer des sessions, envoyer des messages et lire l'historique sans passer par l'interface web.

## Authentification

Toutes les requêtes nécessitent une authentification. Trois méthodes sont acceptées (par ordre de priorité) :

| Méthode | Comment |
|---------|---------|
| Session Jeedom | Cookie de session navigateur (usage UI) |
| Header `X-API-Key` | Hash utilisateur ou clé API Jeedom core |
| Paramètre `user_hash` | Hash utilisateur en query string |

Le **hash utilisateur** se récupère dans Jeedom : *Réglages → Utilisateurs → votre compte → Hash*.

La **clé API Jeedom core** se récupère dans *Réglages → Système → Configuration → API*.

## Base URL

```
https://<votre-jeedom>/plugins/alfred/api/conversation.php
```

---

## Endpoints

### Lister les conversations

```
GET /conversation.php
```

Retourne les conversations de l'utilisateur authentifié, triées par dernière activité.

**Paramètres optionnels**

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `limit`   | int  | 50     | Nombre max de conversations |

**Exemple**

```bash
curl https://<jeedom>/plugins/alfred/api/conversation.php \
  -H "X-API-Key: <hash>"
```

**Réponse**

```json
{
  "success": true,
  "count": 2,
  "data": [
    {
      "id": "93",
      "session_id": "261974e8-183d-4fa3-972e-7640ff332e97",
      "title": "Ma conversation",
      "created_at": "2026-05-18 11:04:14",
      "updated_at": "2026-05-18 11:07:22",
      "user_login": "myst"
    }
  ]
}
```

---

### Créer une conversation

```
POST /conversation.php
```

**Body JSON**

| Champ   | Type   | Requis | Description |
|---------|--------|--------|-------------|
| `title` | string | non    | Titre de la conversation |

**Exemple**

```bash
curl -X POST https://<jeedom>/plugins/alfred/api/conversation.php \
  -H "X-API-Key: <hash>" \
  -H "Content-Type: application/json" \
  -d '{"title": "Automatisation lumières"}'
```

**Réponse** `201`

```json
{
  "success": true,
  "data": {
    "session_id": "9442c914-2175-40d2-b877-cf270fddec76",
    "title": "Automatisation lumières",
    "created_at": "2026-05-18 10:52:09",
    "updated_at": "2026-05-18 10:52:09",
    "user_login": "myst"
  }
}
```

---

### Envoyer un message et obtenir une réponse

```
POST /conversation.php?session_id=<uuid>&action=message
```

Envoie un message à Alfred et attend sa réponse complète (bloquant, max 120 s).

**Body JSON**

| Champ     | Type   | Requis | Description |
|-----------|--------|--------|-------------|
| `content` | string | oui    | Message de l'utilisateur |

**Exemple**

```bash
curl -X POST "https://<jeedom>/plugins/alfred/api/conversation.php?session_id=<uuid>&action=message" \
  -H "X-API-Key: <hash>" \
  -H "Content-Type: application/json" \
  -d '{"content": "Quelle heure est-il ?"}'
```

**Réponse** `200`

```json
{
  "success": true,
  "message": "Quelle heure est-il ?",
  "reply": "Il est actuellement 11h07."
}
```

---

### Récupérer une conversation et ses messages

```
GET /conversation.php?session_id=<uuid>
```

**Exemple**

```bash
curl "https://<jeedom>/plugins/alfred/api/conversation.php?session_id=<uuid>" \
  -H "X-API-Key: <hash>"
```

**Réponse** `200`

```json
{
  "success": true,
  "session": {
    "session_id": "261974e8-...",
    "title": "Ma conversation",
    "user_login": "myst"
  },
  "messages": [
    { "role": "user",      "content": "Quelle heure est-il ?" },
    { "role": "assistant", "content": "Il est actuellement 11h07." }
  ]
}
```

---

### Lister les messages d'une conversation

```
GET /conversation.php?session_id=<uuid>&action=messages
```

**Réponse** `200`

```json
{
  "success": true,
  "count": 2,
  "messages": [
    { "role": "user",      "content": "Bonjour !" },
    { "role": "assistant", "content": "Bonjour ! Comment puis-je vous aider ?" }
  ]
}
```

---

### Supprimer une conversation

```
DELETE /conversation.php?session_id=<uuid>
```

**Exemple**

```bash
curl -X DELETE "https://<jeedom>/plugins/alfred/api/conversation.php?session_id=<uuid>" \
  -H "X-API-Key: <hash>"
```

**Réponse** `200`

```json
{
  "success": true,
  "message": "Conversation deleted"
}
```

---

## Codes d'erreur

| Code | Signification |
|------|---------------|
| 401  | Non authentifié — clé invalide ou absente |
| 403  | Accès refusé — la conversation appartient à un autre utilisateur |
| 404  | Conversation introuvable |
| 405  | Méthode HTTP non supportée |
| 500  | Erreur interne |

Les erreurs retournent un objet `{ "error": "..." }`.
