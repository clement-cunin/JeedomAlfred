<?php

require_once __DIR__ . '/alfredCmd.class.php';

class alfred extends eqLogic {

    // -------------------------------------------------------------------------
    // Plugin lifecycle
    // -------------------------------------------------------------------------

    public static function activate() {
        // Ensure DB tables exist (idempotent — safe to call on every activation)
        require_once __DIR__ . '/alfredMigration.class.php';
        alfredMigration::runPending();
        // Set default provider
        if (config::byKey('provider', __CLASS__) === '') {
            config::save('provider', 'mistral', __CLASS__);
        }
        // Migrate provider_chain to self-contained entry objects
        $rawChain = config::byKey('provider_chain', __CLASS__);
        $decoded  = is_array($rawChain) ? $rawChain : (json_decode((string)$rawChain, true) ?: []);
        $needsObjectMigration = !empty($decoded) && is_string($decoded[0]);
        $needsDefault         = empty($decoded);
        if ($needsObjectMigration || $needsDefault) {
            if ($needsDefault) {
                $decoded = [(string)config::byKey('provider', __CLASS__) ?: 'mistral'];
            }
            $newChain = [];
            foreach ($decoded as $slug) {
                if (!is_string($slug)) { $newChain[] = $slug; continue; }
                $entry = ['id' => self::generateSessionId(), 'type' => $slug, 'enabled' => true];
                if ($slug === 'ollama') {
                    $entry['base_url'] = (string)config::byKey('ollama_base_url', __CLASS__) ?: 'http://localhost:11434';
                } else {
                    $entry['api_key'] = (string)config::byKey($slug . '_api_key', __CLASS__);
                }
                $entry['model'] = (string)config::byKey($slug . '_model', __CLASS__);
                $newChain[] = $entry;
            }
            config::save('provider_chain', json_encode($newChain), __CLASS__);
        }
        // Set default models
        $defaults = [
            'mistral_model'            => 'mistral-large-latest',
            'gemini_model'             => 'gemini-1.5-pro',
            'ollama_base_url'          => 'http://localhost:11434',
            'ollama_model'             => 'mistral:latest',
            'max_iterations'           => '10',
            'system_prompt'            => 'You are Alfred, an AI assistant integrated into a Jeedom home automation system. You help the user control and monitor their smart home. Be concise and friendly.',
            'journal_daily_enabled'    => '0',
            'journal_daily_expiry_days' => '10',
        ];
        foreach ($defaults as $key => $value) {
            if (config::byKey($key, __CLASS__) === '') {
                config::save($key, $value, __CLASS__);
            }
        }

        // Migrate legacy mcp_url / mcp_api_key to the new mcp_servers list
        if (config::byKey('mcp_servers', __CLASS__) === '') {
            $oldUrl    = config::byKey('mcp_url', __CLASS__);
            $oldApiKey = config::byKey('mcp_api_key', __CLASS__);

            // Auto-detect JeedomMCP internal URL if not already set
            if ($oldUrl === '') {
                $oldUrl = network::getNetworkAccess('internal', 'proto:ip:port:comp')
                        . '/plugins/jeedomMCP/api/mcp.php';
            }
            // Mirror JeedomMCP API key if available
            if ($oldApiKey === '') {
                $oldApiKey = (string)config::byKey('mcpApiKey', 'jeedomMCP');
            }

            $servers = [];
            if ($oldUrl !== '') {
                $servers[] = [
                    'name'         => 'JeedomMCP',
                    'slug'         => 'jeedom',
                    'url'          => $oldUrl,
                    'auth_header'  => 'X-API-Key',
                    'auth_value'   => $oldApiKey,
                    'prefix_tools' => false,
                    'enabled'      => true,
                ];
            }
            config::save('mcp_servers', json_encode($servers), __CLASS__);
        }
    }

    public static function cron() {
        // Process any scheduled wakeups whose run_at has been reached (cron strategy only;
        // background-strategy schedules run in their own spawned process).
        require_once __DIR__ . '/alfredAsyncTask.class.php';
        require_once __DIR__ . '/alfredLLM.class.php';
        require_once __DIR__ . '/alfredMCP.class.php';
        require_once __DIR__ . '/alfredMCPRegistry.class.php';
        require_once __DIR__ . '/alfredConversation.class.php';
        require_once __DIR__ . '/alfredMemory.class.php';
        require_once __DIR__ . '/alfredAgent.class.php';
        try {
            alfredAsyncTask::processPending();
        } catch (Exception $e) {
            log::add('alfred_cron', 'error', 'cron: alfredAsyncTask failed — ' . $e->getMessage());
        }
    }

    public static function cronDaily() {
        require_once __DIR__ . '/alfredLLM.class.php';
        require_once __DIR__ . '/alfredConversation.class.php';
        require_once __DIR__ . '/alfredMemory.class.php';
        require_once __DIR__ . '/alfredJournal.class.php';
        try {
            alfredJournal::cronDaily();
        } catch (Exception $e) {
            log::add('alfred_cron', 'error', 'cronDaily: alfredJournal failed — ' . $e->getMessage());
        }
        try {
            alfredMemory::cronDaily();
        } catch (Exception $e) {
            log::add('alfred_cron', 'error', 'cronDaily: memory cleanup failed — ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    public static function getProvider(): string {
        foreach (self::getProviderChain() as $entry) {
            if (is_string($entry)) return $entry;
            if (is_array($entry) && !empty($entry['type'])) {
                if (!isset($entry['enabled']) || $entry['enabled']) return $entry['type'];
            }
        }
        return (string)config::byKey('provider', __CLASS__);
    }

    public static function getProviderChain(): array {
        $raw = config::byKey('provider_chain', __CLASS__);
        if (is_array($raw)) return $raw;
        $json = (string)$raw;
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function getApiKey(string $provider = ''): string {
        if ($provider === '') {
            $provider = self::getProvider();
        }
        return (string)config::byKey($provider . '_api_key', __CLASS__);
    }

    public static function getModel(string $provider = ''): string {
        if ($provider === '') {
            $provider = self::getProvider();
        }
        return (string)config::byKey($provider . '_model', __CLASS__);
    }

    public static function getBaseUrl(string $provider = ''): string {
        if ($provider === '') {
            $provider = self::getProvider();
        }
        return (string)config::byKey($provider . '_base_url', __CLASS__);
    }

    public static function getMcpServers(): array {
        $raw = config::byKey('mcp_servers', __CLASS__);
        if (is_array($raw)) {
            return $raw;
        }
        $json = (string)$raw;
        if ($json === '') {
            return [];
        }
        $servers = json_decode($json, true);
        return is_array($servers) ? $servers : [];
    }

    public static function getSystemPrompt(): string {
        return (string)config::byKey('system_prompt', __CLASS__);
    }

    public static function getFirstInstallPrompt(): string {
        return (string)config::byKey('first_install_prompt', __CLASS__);
    }

    public static function getNewUserPrompt(): string {
        return (string)config::byKey('new_user_prompt', __CLASS__);
    }

    public static function getMaxIterations(): int {
        $v = (int)config::byKey('max_iterations', __CLASS__);
        return $v > 0 ? $v : 10;
    }

    public static function tempDisableProvider(string $id, int $seconds): void {
        if ($id === '') return;
        $raw = (string)config::byKey('provider_temp_disabled', __CLASS__);
        $map = ($raw !== '') ? (json_decode($raw, true) ?: []) : [];
        $map[$id] = time() + $seconds;
        config::save('provider_temp_disabled', json_encode($map), __CLASS__);
    }

    public static function isTempDisabled(string $id): bool {
        if ($id === '') return false;
        $raw = (string)config::byKey('provider_temp_disabled', __CLASS__);
        if ($raw === '') return false;
        $map = json_decode($raw, true);
        if (!is_array($map) || !isset($map[$id])) return false;
        if (time() < (int)$map[$id]) return true;
        unset($map[$id]);
        config::save('provider_temp_disabled', json_encode($map), __CLASS__);
        return false;
    }

    // -------------------------------------------------------------------------
    // Phone equipment helpers
    // -------------------------------------------------------------------------

    public function isPhone(): bool
    {
        return $this->getConfiguration('alfred_type') === 'phone';
    }

    public static function getPhones(): array
    {
        return array_values(array_filter(
            self::byType('alfred'),
            function ($eq) { return $eq->getConfiguration('alfred_type') === 'phone'; }
        ));
    }

    /**
     * Ensure the Phone equipment has exactly one push command.
     * Also cleans up legacy commands from older versions.
     */
    public function postSave(): void
    {
        if (!$this->isPhone()) {
            return;
        }

        // Remove legacy "Envoyer message" command if it exists
        $simple = $this->getCmd(null, 'alfred_push_simple');
        if ($simple) {
            $simple->remove();
        }

        // Create or rename the single conversation command
        $cmd = $this->getCmd(null, 'alfred_push_reflect');
        if (!$cmd) {
            $cmd = new alfredCmd();
            $cmd->setLogicalId('alfred_push_reflect');
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('action');
            $cmd->setSubType('message');
        }
        $cmd->setName('Démarrer une conversation');
        $cmd->setSubType('alfred_conversation');
        $cmd->save();
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    public static function generateSessionId(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
