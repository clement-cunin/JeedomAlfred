<?php

class alfredCmd extends cmd
{
    /**
     * Render the scenario block for the "Démarrer une conversation" command.
     * Bypasses Jeedom's widget template discovery (which resets 'default' widget_name
     * to an empty config key for unknown subTypes) and loads the plugin template directly.
     */
    public function toHtml($_version = 'dashboard', $_options = '')
    {
        if ($_version !== 'scenario' || $this->getLogicalId() !== 'alfred_push_reflect') {
            return parent::toHtml($_version, $_options);
        }

        log::add('alfred', 'info', 'toHtml _options raw: ' . var_export($_options, true));

        if (is_array($_options)) {
            $opts = $_options;
        } else {
            $opts = is_json($_options, []);
            if (!is_array($opts)) {
                $opts = [];
            }
        }
        // Jeedom stores scenario block fields under an 'options' sub-key
        if (isset($opts['options']) && is_array($opts['options'])) {
            $opts = $opts['options'];
        }

        log::add('alfred', 'info', 'toHtml opts resolved: ' . json_encode($opts));

        $uid  = 'cmd' . $this->getId() . eqLogic::UIDDELIMITER . mt_rand() . eqLogic::UIDDELIMITER;
        $id   = $this->getId();

        $tpl = (string) file_get_contents(
            __DIR__ . '/../../core/template/scenario/cmd.action.alfred_conversation.default.html'
        );

        return str_replace(
            ['#uid#', '#id#', '#session_name#', '#message#', '#prompt#'],
            [
                $uid,
                $id,
                htmlspecialchars((string) ($opts['session_name'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($opts['message']      ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($opts['prompt']       ?? ''), ENT_QUOTES),
            ],
            $tpl
        );
    }

    public function execute($_options = [])
    {
        $logicalId = $this->getLogicalId();

        if ($logicalId !== 'alfred_push_reflect') {
            return;
        }

        require_once __DIR__ . '/alfredPush.class.php';
        require_once __DIR__ . '/alfredAsyncTask.class.php';

        $eqLogic = $this->getEqLogic();
        $name    = trim((string) ($_options['session_name'] ?? ''));
        $visible = trim((string) ($_options['message']      ?? ''));
        $prompt  = trim((string) ($_options['prompt']       ?? ''));

        if ($visible === '') {
            log::add('alfred', 'warning', "alfredCmd::execute: empty message, skipping");
            return;
        }

        $sub = alfredPush::getSubscription($eqLogic->getId());
        if (!$sub) {
            log::add('alfred', 'warning', "alfredCmd::execute: no subscription for phone #{$eqLogic->getId()}");
            return;
        }

        $ownerLogin = $eqLogic->getConfiguration('owner_login') ?: null;
        $sessionId  = alfred::generateSessionId();

        $taskId = alfredAsyncTask::create(
            $sessionId,
            'push_reflect',
            "Conversation : " . substr($visible, 0, 80),
            [
                'eqLogic_id'  => $eqLogic->getId(),
                'session_id'  => $sessionId,
                'name'        => $name,
                'visible'     => $visible,
                'prompt'      => $prompt,
                'owner_login' => $ownerLogin,
                'sub'         => $sub,
            ]
        );

        $php     = PHP_BINARY !== '' ? PHP_BINARY : trim((string) shell_exec('which php 2>/dev/null'));
        $script  = dirname(__DIR__, 2) . '/api/push_wakeup.php';
        $logDir  = dirname(__DIR__, 4) . '/log';
        $logFile = (is_dir($logDir) ? $logDir : sys_get_temp_dir()) . '/alfred_cron';

        exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
             . ' --task_id=' . $taskId
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');

        log::add('alfred', 'info', "alfredCmd::execute: task #{$taskId} spawned for phone #{$eqLogic->getId()}");
    }
}
