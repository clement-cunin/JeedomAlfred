# Architecture technique

Documentation à destination des développeurs souhaitant comprendre ou contribuer au plugin Alfred.

## Vue d'ensemble

Alfred est un plugin Jeedom **PHP pur** — pas de démon, pas de processus background permanent. Les requêtes sont servies directement par Apache via les endpoints PHP.

```
Navigateur
    │
    ├── GET /plugins/alfred/desktop/php/alfred.php   → Interface de chat (Jeedom)
    │
    └── POST /plugins/alfred/api/chat.php            → Agent IA (SSE streaming)
            │
            ├── alfredAgent          → Boucle ReAct
            │       ├── alfredLLM    → Appel LLM (Claude / GPT / Gemini)
            │       └── alfredMCPRegistry → Appels outils MCP
            │
            ├── alfredConversation   → Persistance messages (DB)
            ├── alfredMemory         → Mémoire persistante (DB)
            └── alfredScheduler      → Planification différée
```

## Classes PHP

### `alfred.class.php` — Classe principale

Extends `eqLogic` de Jeedom. Point d'entrée du plugin.

- `activate()` : initialise les valeurs par défaut, migre la config legacy
- `cron()` : exécuté chaque minute par Jeedom, traite les tâches planifiées en attente
- Helpers de configuration : `getProvider()`, `getApiKey()`, `getModel()`, etc.

### `alfredAgent.class.php` — Boucle ReAct

Orchestrateur principal de l'agent IA.

**Boucle d'exécution** :
1. Charge ou crée la session de conversation
2. Construit le prompt système complet
3. Récupère la liste des outils MCP disponibles
4. Boucle jusqu'à `maxIterations` :
   - Appelle `llm->chatStream()`, émet les deltas SSE
   - Si `stop_reason = tool_use` : exécute les outils, ajoute les résultats, reboucle
   - Si `stop_reason = end_turn` : persiste la réponse, sort

**Outils synthétiques** (traités localement, sans MCP) :

| Outil | Action |
|---|---|
| `alfred_schedule` | Planifie une exécution différée |
| `alfred_memory_save` | Crée une mémoire |
| `alfred_memory_update` | Met à jour une mémoire par libellé |
| `alfred_memory_forget` | Supprime une mémoire par libellé |

### `alfredLLM.class.php` — Couche d'abstraction LLM

Classe abstraite `alfredLLMAdapter` + factory `alfredLLM::make(provider, apiKey, model)`.

Interface commune :
- `chat()` — appel synchrone
- `chatStream($messages, $tools, $onDelta)` — streaming avec callback delta
- `testConnection()` — test de la clé API
- `listModels()` — liste les modèles disponibles

**Format de message interne** (canonique) :

```json
{
  "role": "user|assistant|tool",
  "content": "...",
  "tool_calls": [...],
  "tool_call_id": "...",
  "name": "..."
}
```

Chaque adaptateur convertit ce format vers le format natif du fournisseur.

### `alfredMCPRegistry.class.php` — Agrégateur multi-serveurs

- `fromConfig()` : lit la config `mcp_servers`, instancie un `alfredMCP` par serveur actif
- `addServer(mcp, slug, prefixTools)` : fusionne les outils ; si `prefixTools=true`, expose comme `slug__nom_outil`
- `listTools()` : liste unifiée pour le LLM
- `callTool(name, args)` : route vers le bon serveur avec le nom d'origine (non préfixé)

### `alfredMCP.class.php` — Client MCP

Client JSON-RPC 2.0 sur HTTP POST (transport streamable-http).

- `listTools()` : appelle `tools/list`, met en cache
- `callTool(name, arguments)` : appelle `tools/call`, décode la réponse texte JSON

### `alfredConversation.class.php` — Persistance

Gère les tables `alfred_conversation` et `alfred_message`.

- Les `tool_calls` et métadonnées Gemini (pensées) sont stockés dans la colonne `metadata` (JSON)
- `getMessages()` reconstruit le format interne complet depuis la DB

### `alfredMigration.class.php` — Migrations de schéma

Runner de migrations versionnées et idempotentes. Chaque migration est identifiée par un numéro séquentiel. La version courante est stockée dans la config Jeedom.

## Endpoint de streaming : `api/chat.php`

**Entrée** (GET ou JSON body) :

| Paramètre | Type | Description |
|---|---|---|
| `session_id` | string | UUID de la session (optionnel, créée si absent) |
| `message` | string | Message de l'utilisateur |
| `extra_iterations` | int | Itérations supplémentaires à autoriser |

**Authentification** : cookie de session Jeedom ou paramètre `user_hash`.

**Sortie** : Server-Sent Events

| Événement | Données | Description |
|---|---|---|
| `delta` | `{"text": "..."}` | Fragment de réponse textuelle |
| `tool_call` | `{"id", "name", "input"}` | Début d'un appel d'outil |
| `tool_result` | `{"id", "result", "error"}` | Résultat d'un appel d'outil |
| `done` | `{"text": "..."}` | Réponse complète |
| `error` | `{"message": "..."}` | Erreur |
| `debug` | `{"system_prompt": "..."}` | Prompt système complet (admins uniquement) |

## AJAX admin : `core/ajax/alfred.ajax.php`

Toutes les actions requièrent une session Jeedom. Les actions admin nécessitent `isConnect('admin')`.

| Action | Auth | Description |
|---|---|---|
| `saveMCPServers` | admin | Sauvegarde la liste JSON des serveurs MCP |
| `testMCP` | user | Teste la connexion et liste les outils d'un serveur |
| `testLLM` | user | Teste la clé API du fournisseur |
| `listModels` | user | Récupère les modèles disponibles |
| `getSessions` | user | Liste les sessions de conversation |
| `deleteSession` | user | Supprime une session et ses messages |
| `renameSession` | user | Renomme une session |
| `getMessages` | user | Récupère l'historique d'une session |
| `runMigrations` | admin | Exécute les migrations en attente |
| `listMemories` | admin | Liste toutes les mémoires |
| `updateMemory` | admin | Modifie une mémoire |
| `deleteMemory` | admin | Supprime une mémoire |

## Base de données

```sql
alfred_conversation (session_id VARCHAR PK, title, created_at, updated_at)
alfred_message      (id INT PK, session_id FK, role ENUM, content LONGTEXT, metadata JSON, created_at)
alfred_memory       (id INT PK, scope VARCHAR, label VARCHAR(100), content TEXT, created_at, updated_at)
alfred_schedule     (id INT PK, session_id, instruction TEXT, run_at DATETIME, strategy ENUM, status ENUM, error_msg)
```

## Compatibilité

- **PHP 7.4+** (Jeedom sur Raspberry Pi OS / Debian)
- Pas d'utilisation de `CurlHandle`, `enum`, `readonly`, ni d'autres types PHP 8+
- Pas de dépendances Composer

## PWA (Phase 6 — en cours)

Le répertoire `chat/` contient les fichiers de la future application PWA standalone :
- `index.php` — page autonome (placeholder)
- `manifest.json` — manifest PWA (nom, icônes, display standalone, orientation portrait)

L'installation PWA sera disponible sur mobile via "Ajouter à l'écran d'accueil" depuis le navigateur.
