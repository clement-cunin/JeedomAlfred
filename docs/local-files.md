# Fichiers locaux (pièces jointes de session)

Alfred permet d'attacher des fichiers à une conversation. Le LLM peut ensuite les lire à la demande, et il peut lui-même créer des fichiers que l'utilisateur pourra télécharger.

## Fonctionnement général

Les fichiers ne sont **pas** envoyés en masse au LLM à chaque tour. Alfred adopte une approche lazy :

1. La liste des fichiers attachés à la session est injectée dans le prompt système (nom, type, taille, `file_id`).
2. Le LLM dispose de l'outil `uploaded_file_read` pour lire un fichier quand il en a besoin.
3. Le fichier est transmis au LLM (en base64) uniquement au moment où il le demande.

Cette approche économise les tokens API et est compatible avec tous les fournisseurs LLM.

## Upload depuis l'interface

L'utilisateur peut joindre des fichiers depuis la zone de saisie. Formats acceptés :

| Type | Extensions |
|---|---|
| Images | `jpg`, `jpeg`, `png`, `gif`, `webp` |
| Documents | `pdf` |

Taille maximale : **20 Mo** par fichier.

Les fichiers attachés apparaissent dans une barre au-dessus de la zone de saisie. Chaque fichier peut être ouvert en inline, partagé (si l'API Web Share est disponible) ou téléchargé.

## Stockage

Les fichiers sont stockés dans un dossier temporaire isolé par session :

```
{sys_get_temp_dir()}/alfred/{session_id}/{file_id}.{ext}
{sys_get_temp_dir()}/alfred/{session_id}/{file_id}.json   ← métadonnées
```

Le `file_id` est un identifiant hex de 16 caractères généré aléatoirement (`bin2hex(random_bytes(8))`).

Structure du fichier de métadonnées :

```json
{
  "file_id":       "a3f8c1d2e4b56789",
  "original_name": "facture.pdf",
  "mime_type":     "application/pdf",
  "size":          45312,
  "filename":      "a3f8c1d2e4b56789.pdf"
}
```

Tous les fichiers d'une session sont supprimés à la suppression de celle-ci.

## Endpoints HTTP

### Upload — `POST /plugins/alfred/api/upload.php`

Authentification : session PHP active ou paramètre `user_hash`.

| Champ | Type | Description |
|---|---|---|
| `file` | fichier | Le fichier à uploader |
| `session_id` | string | UUID de la conversation |
| `user_hash` | string | Token d'auth hors session PHP |

Réponse `200` :

```json
{
  "file_id":   "a3f8c1d2e4b56789",
  "filename":  "facture.pdf",
  "mime_type": "application/pdf",
  "size":      45312
}
```

### Consultation / téléchargement — `GET /plugins/alfred/api/file.php`

Sert le fichier inline avec le bon `Content-Type`. Utilisé par l'interface pour l'ouverture et le partage.

| Paramètre | Description |
|---|---|
| `session_id` | UUID de la conversation |
| `file_id` | Identifiant du fichier |
| `user_hash` | Token d'auth hors session PHP |

---

## Outils mis à disposition du LLM

### `uploaded_file_read`

Disponible uniquement si la session contient au moins un fichier attaché.

```json
{
  "name": "uploaded_file_read",
  "description": "Read the content of a file attached to this conversation. Returns the file as base64-encoded data along with its MIME type. Use this when you need to analyse the content of an attached file.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "file_id": {
        "type": "string",
        "description": "The file_id of the uploaded file, as listed in the system context."
      }
    },
    "required": ["file_id"]
  }
}
```

Réponse retournée au LLM :

```json
{
  "file_id":   "a3f8c1d2e4b56789",
  "filename":  "facture.pdf",
  "mime_type": "application/pdf",
  "size":      45312,
  "data":      "<base64>"
}
```

### `file_create`

Permet au LLM de créer un fichier téléchargeable dans la session courante. Le fichier apparaît dans la barre des pièces jointes dès le tour suivant.

```json
{
  "name": "file_create",
  "description": "Save content as a downloadable file in the current session. Returns a file_id. The file will appear in the \"Attached files\" list from the next turn onward and can be downloaded by the user. Use this when you have produced or retrieved binary content (e.g. a PDF from Paperless) that the user should be able to download.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "content_base64": {
        "type": "string",
        "description": "Base64-encoded file content."
      },
      "filename": {
        "type": "string",
        "description": "Desired filename with extension (e.g. \"invoice.pdf\")."
      },
      "mime_type": {
        "type": "string",
        "description": "MIME type (e.g. \"application/pdf\"). Guessed from filename extension if omitted."
      }
    },
    "required": ["content_base64", "filename"]
  }
}
```

Réponse retournée au LLM :

```json
{
  "file_id":   "b7e2a9c0f1d34512",
  "filename":  "rapport.pdf",
  "mime_type": "application/pdf",
  "size":      12800
}
```

Types MIME déduits automatiquement depuis l'extension si `mime_type` est omis :

| Extension | MIME type |
|---|---|
| `pdf` | `application/pdf` |
| `jpg` / `jpeg` | `image/jpeg` |
| `png` | `image/png` |
| `gif` | `image/gif` |
| `webp` | `image/webp` |
| `txt` | `text/plain` |
| `html` | `text/html` |
| `json` | `application/json` |
| autres | `application/octet-stream` |

---

## API pour les plugins externes

La classe `alfredAgent` expose des méthodes statiques permettant à d'autres plugins d'injecter ou de lire des fichiers dans une session Alfred active.

> Ces méthodes sont conçues pour être appelées depuis un outil MCP (handler PHP). Le fichier créé est automatiquement notifié au frontend via l'event SSE `file_added` au prochain flush de la boucle de l'agent.

### `alfredAgent::registerFile()`

Enregistre un contenu binaire comme fichier de session. Retourne le `file_id`.

```php
public static function registerFile(
    string $sessionId,
    string $content,      // contenu binaire brut
    string $originalName, // nom affiché à l'utilisateur
    string $mimeType
): string
```

Exemple :

```php
$fileId = alfredAgent::registerFile(
    $sessionId,
    file_get_contents('/tmp/export.pdf'),
    'export.pdf',
    'application/pdf'
);
```

Lance une `Exception` si le dossier de session ne peut pas être créé.

### `alfredAgent::registerFileFromPath()`

Variante de `registerFile()` qui lit depuis un chemin existant sur le disque.

```php
public static function registerFileFromPath(
    string $sessionId,
    string $sourcePath,   // chemin absolu vers le fichier source
    string $originalName,
    string $mimeType
): string
```

Lance une `Exception` si le fichier source ne peut pas être lu.

### `alfredAgent::listUploadedFiles()`

Retourne la liste de tous les fichiers attachés à une session (tableau de métadonnées).

```php
public static function listUploadedFiles(string $sessionId): array
```

Chaque entrée du tableau correspond à un fichier de métadonnées :

```php
[
    'file_id'       => 'a3f8c1d2e4b56789',
    'original_name' => 'facture.pdf',
    'mime_type'     => 'application/pdf',
    'size'          => 45312,
    'filename'      => 'a3f8c1d2e4b56789.pdf',
]
```

### `alfredAgent::getFileMeta()`

Retourne les métadonnées d'un fichier précis, ou `null` s'il n'existe pas.

```php
public static function getFileMeta(string $sessionId, string $fileId): ?array
```

### `alfredAgent::getFilePath()`

Retourne le chemin absolu vers le fichier sur le disque, ou `null` s'il est introuvable.

```php
public static function getFilePath(string $sessionId, string $fileId): ?string
```

Exemple — lire le contenu d'un fichier depuis un plugin :

```php
$path = alfredAgent::getFilePath($sessionId, $fileId);
if ($path !== null) {
    $content = file_get_contents($path);
}
```

### `alfredAgent::cleanupSessionFiles()`

Supprime tous les fichiers et le dossier de session. Appelé automatiquement à la suppression d'une session, inutile de l'appeler manuellement.

```php
public static function cleanupSessionFiles(string $sessionId): void
```

---

## Notification temps réel (SSE)

Quand un plugin externe appelle `registerFile()`, Alfred écrit l'événement dans un fichier `_events.json` dans le dossier de session. La boucle de l'agent (`flushPendingFileEvents`) le lit et émet un event SSE `file_added` vers le frontend :

```json
{
  "file_id":   "b7e2a9c0f1d34512",
  "filename":  "rapport.pdf",
  "mime_type": "application/pdf",
  "size":      12800
}
```

Le frontend ajoute automatiquement le fichier à la barre des pièces jointes sans rechargement.
