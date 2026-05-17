<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    try {
        include_file('core', 'alfredMigration', 'class', 'alfred');
        alfredMigration::runPending();
    } catch (Exception $e) {
        log::add('alfred', 'error', 'Migration failed in ajax: ' . $e->getMessage());
    }

    $action = init('action');

    if ($action === 'saveMCPServers') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        $value = init('mcp_servers', '[]');
        config::save('mcp_servers', $value, 'alfred');
        ajax::success();
    }

    if ($action === 'testMCP') {
        require_once __DIR__ . '/../class/alfredMCP.class.php';
        $url        = init('url');
        $authHeader = init('auth_header') ?: 'X-API-Key';
        $authValue  = init('auth_value');
        if ($url === '') {
            throw new Exception('Missing url');
        }
        $mcp   = new alfredMCP($url, $authHeader, $authValue);
        $tools = $mcp->listTools();
        ajax::success([
            'count' => count($tools),
            'tools' => array_column($tools, 'name'),
        ]);
    }

    if ($action === 'testLLM') {
        require_once __DIR__ . '/../class/alfred.class.php';
        require_once __DIR__ . '/../class/alfredLLM.class.php';
        // Accept form values directly (allows testing before saving)
        $provider = init('provider') ?: alfred::getProvider();
        $apiKey   = init('api_key')  ?: alfred::getApiKey($provider);
        $model    = init('model')    ?: alfred::getModel($provider);
        $llm = alfredLLM::make($provider, $apiKey, $model);
        ajax::success($llm->testConnection());
    }

    if ($action === 'listModels') {
        require_once __DIR__ . '/../class/alfred.class.php';
        require_once __DIR__ . '/../class/alfredLLM.class.php';
        $provider = init('provider');
        $apiKey   = init('api_key');
        if ($provider === '' || $apiKey === '') {
            throw new Exception('Missing provider or api_key');
        }
        // Use a dummy model — listModels() doesn't use $this->model
        $llm = alfredLLM::make($provider, $apiKey, 'none');
        ajax::success($llm->listModels());
    }

    if ($action === 'getSessions') {
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        $connectedUser = $_SESSION['user'] ?? null;
        $currentLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;
        ajax::success(alfredConversation::listSessions(50, $currentLogin));
    }

    if ($action === 'deleteSession') {
        $sessionId = init('session_id');
        if ($sessionId === '') {
            throw new Exception('Missing session_id');
        }
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        $connectedUser = $_SESSION['user'] ?? null;
        $currentLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;
        if (!isConnect('admin') && !alfredConversation::sessionBelongsTo($sessionId, $currentLogin)) {
            throw new Exception(__('401 - Unauthorized access', __FILE__));
        }
        alfredConversation::deleteSession($sessionId);
        require_once __DIR__ . '/../class/alfredAgent.class.php';
        alfredAgent::cleanupSessionFiles($sessionId);
        ajax::success();
    }

    if ($action === 'renameSession') {
        $sessionId = init('session_id');
        $title     = init('title');
        if ($sessionId === '') throw new Exception('Missing session_id');
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        $connectedUser = $_SESSION['user'] ?? null;
        $currentLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;
        if (!isConnect('admin') && !alfredConversation::sessionBelongsTo($sessionId, $currentLogin)) {
            throw new Exception(__('401 - Unauthorized access', __FILE__));
        }
        alfredConversation::updateSessionTitle($sessionId, $title);
        ajax::success();
    }

    if ($action === 'getMessages') {
        $sessionId = init('session_id');
        if ($sessionId === '') throw new Exception('Missing session_id');
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        $connectedUser = $_SESSION['user'] ?? null;
        $currentLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;
        if (!isConnect('admin') && !alfredConversation::sessionBelongsTo($sessionId, $currentLogin)) {
            throw new Exception(__('401 - Unauthorized access', __FILE__));
        }
        ajax::success(alfredConversation::getDisplayMessages($sessionId));
    }

    if ($action === 'getSessionFiles') {
        $sessionId = init('session_id');
        if ($sessionId === '') throw new Exception('Missing session_id');
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        require_once __DIR__ . '/../class/alfredAgent.class.php';
        $connectedUser = $_SESSION['user'] ?? null;
        $currentLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;
        if (!isConnect('admin') && !alfredConversation::sessionBelongsTo($sessionId, $currentLogin)) {
            throw new Exception(__('401 - Unauthorized access', __FILE__));
        }
        $files = alfredAgent::listUploadedFiles($sessionId);
        $result = array_map(function ($f) {
            return [
                'file_id'   => $f['file_id'],
                'filename'  => $f['original_name'],
                'mime_type' => $f['mime_type'],
                'size'      => $f['size'],
            ];
        }, $files);
        ajax::success($result);
    }

    if ($action === 'runMigrations') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        include_file('core', 'alfredMigration', 'class', 'alfred');
        alfredMigration::runPending();
        ajax::success(alfredMigration::getVersion());
    }

    if ($action === 'listMemories') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        require_once __DIR__ . '/../class/alfredMemory.class.php';
        ajax::success(alfredMemory::loadAll());
    }

    if ($action === 'updateMemory') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        $id      = (int)init('id');
        $label   = trim(init('label'));
        $content = trim(init('content'));
        $scope   = trim(init('scope'));
        if ($id <= 0)        throw new Exception('Missing or invalid id');
        if ($label === '')   throw new Exception('Label must not be empty');
        if ($content === '') throw new Exception('Content must not be empty');
        if ($scope === '')   throw new Exception('Scope must not be empty');
        require_once __DIR__ . '/../class/alfredMemory.class.php';
        alfredMemory::adminUpdate($id, $label, $content, $scope);
        ajax::success();
    }

    if ($action === 'deleteMemory') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        $id = (int)init('id');
        if ($id <= 0) throw new Exception('Missing or invalid id');
        require_once __DIR__ . '/../class/alfredMemory.class.php';
        alfredMemory::forget($id, null);
        ajax::success();
    }

    if ($action === 'createMemory') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        $label   = trim(init('label'));
        $content = trim(init('content'));
        $scope   = trim(init('scope'));
        if ($label === '')   throw new Exception('Label must not be empty');
        if ($content === '') throw new Exception('Content must not be empty');
        if ($scope === '')   throw new Exception('Scope must not be empty');
        require_once __DIR__ . '/../class/alfredMemory.class.php';
        $id  = alfredMemory::save($scope, $label, $content);
        $now = date('Y-m-d H:i:s');
        ajax::success(['id' => $id, 'scope' => $scope, 'label' => $label, 'content' => $content, 'created_at' => $now, 'updated_at' => $now]);
    }

    if ($action === 'previewCategories') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        require_once __DIR__ . '/../class/alfred.class.php';
        require_once __DIR__ . '/../class/alfredMCP.class.php';
        require_once __DIR__ . '/../class/alfredMCPRegistry.class.php';
        require_once __DIR__ . '/../class/alfredToolRouter.class.php';

        $registry = alfredMCPRegistry::fromConfig();
        $tools    = $registry->listTools();

        // Categorize each tool
        $byCategory = [];
        foreach ($tools as $tool) {
            $cat = alfredToolRouter::deriveCategory($tool['name']);
            $byCategory[$cat][] = $tool['name'];
        }
        ksort($byCategory);
        ajax::success($byCategory);
    }

    if ($action === 'listToolCategories') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        require_once __DIR__ . '/../class/alfredToolRouter.class.php';
        // Seed any new default categories not yet in DB (safe for existing installs)
        alfredToolRouter::seedDefaultCategories();
        $categories = alfredToolRouter::loadCategories();
        $result = [];
        foreach ($categories as $cat => $keywords) {
            $result[] = ['category' => $cat, 'keywords' => implode(', ', $keywords)];
        }
        ajax::success($result);
    }

    if ($action === 'saveToolCategories') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        $raw = init('categories', '[]');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new Exception('Invalid categories payload');
        require_once __DIR__ . '/../class/alfredToolRouter.class.php';
        alfredToolRouter::saveCategories($data);
        ajax::success();
    }

    if ($action === 'backtestRouter') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        require_once __DIR__ . '/../class/alfredToolRouter.class.php';
        ajax::success(alfredToolRouter::backtest());
    }

    throw new Exception(__('No method found for: ', __FILE__) . $action);

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
