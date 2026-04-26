<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    $action = init('action');

    if ($action === 'saveMCPServers') {
        if (!isConnect('admin')) throw new Exception(__('401 - Unauthorized access', __FILE__));
        config::save('mcp_servers', init('mcp_servers', '[]'), 'alfred');
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
        ajax::success(alfredConversation::listSessions());
    }

    if ($action === 'deleteSession') {
        $sessionId = init('session_id');
        if ($sessionId === '') {
            throw new Exception('Missing session_id');
        }
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        alfredConversation::deleteSession($sessionId);
        ajax::success();
    }

    if ($action === 'renameSession') {
        $sessionId = init('session_id');
        $title     = init('title');
        if ($sessionId === '') throw new Exception('Missing session_id');
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        alfredConversation::updateSessionTitle($sessionId, $title);
        ajax::success();
    }

    if ($action === 'getMessages') {
        $sessionId = init('session_id');
        if ($sessionId === '') throw new Exception('Missing session_id');
        require_once __DIR__ . '/../class/alfredConversation.class.php';
        ajax::success(alfredConversation::getMessages($sessionId));
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

    throw new Exception(__('No method found for: ', __FILE__) . $action);

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
