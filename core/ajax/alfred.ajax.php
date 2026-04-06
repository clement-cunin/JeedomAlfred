<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    $action = init('action');

    if ($action === 'getSessions') {
        // Returns conversation session list for the current user
        // Implemented in Phase 4
        ajax::success([]);
    }

    if ($action === 'deleteSession') {
        $sessionId = init('session_id');
        if ($sessionId === '') {
            throw new Exception('Missing session_id');
        }
        // Implemented in Phase 4
        ajax::success();
    }

    throw new Exception(__('No method found for: ', __FILE__) . $action);

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
