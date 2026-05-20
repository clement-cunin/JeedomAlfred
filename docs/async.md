# Async tool calls — guide pour les plugins externes

Ce document explique comment implémenter un outil asynchrone dans un plugin Jeedom qui s'intègre à Alfred. Un outil async est un outil dont le traitement est long (scan, upload, conversion…) et s'exécute en arrière-plan pendant qu'Alfred continue à répondre à l'utilisateur.

## Vue d'ensemble

```
Tool call LLM
     │
     ▼
Tool handler (plugin)
  • alfredAsyncTask::create()    → crée la tâche en DB
  • spawn background process     → démarre le traitement
  • return result + _async_task_id
     │
     ▼
Alfred (agent loop)
  • strip _async_task_id
  • saveToolResult()             → "Scan en cours, résultat dans quelques instants"
  • linkMessage()                → message pending avec spinner dans le chat
     │
     ▼
LLM → répond à l'utilisateur "Je lance le scan…"
     │
     ▼ (background)
Background process (plugin)
  • alfredAsyncTask::markRunning()
  • ... traitement ...
  • alfredAsyncTask::resolve() ou ::fail()
  • alfredAgent::resumeWithAsyncToolResult() ou ::resumeWithAsyncToolError()
     │
     ▼
Alfred (continuation LLM turn)
  → "Le scan est terminé, voici les résultats : …"
```

Le chat affiche un indicateur visuel (spinner → ✓ ou ✗) lié à la tâche, sans intervention supplémentaire du plugin.

---

## 1. Dans le handler de l'outil

Le handler est appelé par Alfred quand le LLM décide d'utiliser l'outil. Il doit :

1. Créer la tâche via `alfredAsyncTask::create()`
2. Spawner un processus background
3. Retourner un résultat contenant `_async_task_id`

**Alfred strip automatiquement `_async_task_id` avant de l'envoyer au LLM et à la DB.**

```php
// core/php/myTool.php — handler d'outil appelé par Alfred via MCP ou Jeedom
function handleMyScanTool(string $sessionId, array $params): array
{
    // 1. Créer la tâche async
    $taskId = alfredAsyncTask::create(
        $sessionId,
        'ext_myplugin_scan',           // type libre, convention : ext_<plugin>_<action>
        'Scan en cours…',              // texte affiché dans le spinner
        ['file_id' => $params['file_id'], 'options' => $params['options']]  // payload libre
    );

    // 2. Spawner le processus background
    $php    = PHP_BINARY;
    $script = __DIR__ . '/scan_worker.php';
    $cmd    = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --task_id=' . $taskId;
    exec('nohup ' . $cmd . ' >> ' . sys_get_temp_dir() . '/myplugin_scan.log 2>&1 &');

    // 3. Retourner le résultat (avec _async_task_id — stripped par Alfred avant le LLM)
    return [
        'status'         => 'processing',
        'message'        => 'Scan lancé, le résultat arrivera dans quelques instants.',
        '_async_task_id' => $taskId,
    ];
}
```

> **Pourquoi `_async_task_id` dans le résultat ?**
> Alfred utilise ce marqueur pour créer le message pending *après* avoir sauvegardé le résultat de l'outil, garantissant l'ordre correct d'affichage dans le chat. Il est supprimé avant que le LLM et la DB ne voient quoi que ce soit.

---

## 2. Dans le processus background

Le worker s'exécute en CLI, indépendamment du cycle de requête HTTP d'Alfred.

```php
<?php
// core/php/scan_worker.php

if (php_sapi_name() !== 'cli') { exit(1); }

$opts   = getopt('', ['task_id:']);
$taskId = (int)($opts['task_id'] ?? 0);
if ($taskId <= 0) { exit(1); }

// Bootstrap Jeedom
require_once '/var/www/html/core/php/core.inc.php';

// Charger les classes Alfred nécessaires
require_once '/var/www/html/plugins/alfred/core/class/alfredConversation.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredAsyncTask.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredLLM.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredMCP.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredMCPRegistry.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredMemory.class.php';
require_once '/var/www/html/plugins/alfred/core/class/alfredAgent.class.php';

// Charger vos propres classes
require_once __DIR__ . '/../class/myPlugin.class.php';

// Récupérer la tâche
$task = alfredAsyncTask::getTask($taskId);
if ($task === null || $task['status'] !== 'pending') { exit(0); }

$payload   = json_decode($task['payload'] ?? '{}', true);
$sessionId = $task['session_id'];

alfredAsyncTask::markRunning($taskId);

try {
    // Traitement long
    $result = myPlugin::runScan($payload['file_id'], $payload['options']);

    // Succès
    alfredAsyncTask::resolve($taskId, $result);
    alfredAgent::resumeWithAsyncToolResult(
        $sessionId,
        'ext_myplugin_scan',   // même nom que lors du tool call
        $result
    );

} catch (Exception $e) {
    // Échec
    alfredAsyncTask::fail($taskId, $e->getMessage());
    alfredAgent::resumeWithAsyncToolError(
        $sessionId,
        'ext_myplugin_scan',
        $e->getMessage()
    );
}
```

---

## 3. Référence API

### `alfredAsyncTask::create()`

```php
alfredAsyncTask::create(
    string $sessionId,
    string $type,        // convention : 'ext_<plugin>_<action>'
    string $displayText, // affiché dans le spinner : "Scan en cours…"
    array  $payload = [] // données libres récupérables dans le worker via getTask()
): int                   // task ID
```

Crée la tâche en DB (status `pending`). Ne crée pas encore le message pending — Alfred s'en charge après avoir sauvegardé le résultat de l'outil.

### `alfredAsyncTask::getTask(int $taskId): ?array`

Retourne la ligne complète de la tâche, ou `null` si introuvable. Champs utiles : `session_id`, `payload` (JSON), `status`, `message_id`.

### `alfredAsyncTask::markRunning(int $taskId): void`

Passe le statut à `running` et met à jour le spinner dans le chat.

### `alfredAsyncTask::resolve(int $taskId, array $result = []): void`

Passe le statut à `done`, stocke le résultat, et met à jour l'icône du chat (✓). À appeler **avant** `resumeWithAsyncToolResult()`.

### `alfredAsyncTask::fail(int $taskId, string $errorMsg): void`

Passe le statut à `error`, stocke le message d'erreur, et met à jour l'icône du chat (✗). À appeler **avant** `resumeWithAsyncToolError()`.

### `alfredAgent::resumeWithAsyncToolResult()`

```php
alfredAgent::resumeWithAsyncToolResult(
    string  $sessionId,
    string  $toolName,    // nom de l'outil tel que déclaré dans Alfred
    array   $toolResult,  // résultat à injecter dans le contexte LLM
    ?string $userLogin  = null,  // résolu depuis la session si null
    ?string $userProfil = null
): string // réponse finale du LLM
```

Injecte le résultat et relance un tour LLM pour que l'agent réponde à l'utilisateur.

### `alfredAgent::resumeWithAsyncToolError()`

```php
alfredAgent::resumeWithAsyncToolError(
    string  $sessionId,
    string  $toolName,
    string  $errorMessage,
    ?string $userLogin  = null,
    ?string $userProfil = null
): string
```

Injecte une erreur et relance un tour LLM.

---

## 4. Convention de nommage

| Élément | Convention | Exemple |
|---|---|---|
| `type` dans la table | `ext_<plugin>_<action>` | `ext_jaganin_document_scan` |
| Nom de l'outil MCP | `ext_<plugin>_<action>` | `ext_jaganin_document_scan` |
| Script worker | `<action>_worker.php` | `scan_worker.php` |
| Fichier log | `/log/<plugin>_<action>` | `/log/jaganin_scan` |

---

## 5. Garde-fou : vérification du statut avant exécution

Le worker doit toujours vérifier que la tâche est encore `pending` avant de s'exécuter, pour éviter les doublons en cas de relance :

```php
$task = alfredAsyncTask::getTask($taskId);
if ($task === null || $task['status'] !== 'pending') {
    exit(0); // déjà exécuté ou annulé
}
```
