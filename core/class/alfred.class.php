<?php

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
            config::save('provider', 'anthropic', __CLASS__);
        }
        // Set default models
        $defaults = [
            'anthropic_model' => 'claude-sonnet-4-6',
            'openai_model'    => 'gpt-4o',
            'gemini_model'    => 'gemini-1.5-pro',
            'max_iterations'  => '10',
            'system_prompt'   => 'You are Alfred, an AI assistant integrated into a Jeedom home automation system. You help the user control and monitor their smart home. Be concise and friendly.',
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
        require_once __DIR__ . '/alfredScheduler.class.php';
        require_once __DIR__ . '/alfredLLM.class.php';
        require_once __DIR__ . '/alfredMCP.class.php';
        require_once __DIR__ . '/alfredConversation.class.php';
        require_once __DIR__ . '/alfredAgent.class.php';
        try {
            alfredScheduler::processPending();
        } catch (Exception $e) {
            // Table may not exist yet during install/update — silently skip
            log::add('alfred', 'debug', 'cron: alfredScheduler skipped — ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    public static function getProvider(): string {
        return (string)config::byKey('provider', __CLASS__);
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

    public static function getMcpServers(): array {
        $json = (string)config::byKey('mcp_servers', __CLASS__);
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
