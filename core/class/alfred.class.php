<?php

class alfred extends eqLogic {

    // -------------------------------------------------------------------------
    // Plugin lifecycle
    // -------------------------------------------------------------------------

    public static function activate() {
        // Set default provider
        if (config::byKey('provider', __CLASS__) === '') {
            config::save('provider', 'anthropic', __CLASS__);
        }
        // Set default models
        $defaults = [
            'anthropic_model' => 'claude-sonnet-4-6',
            'openai_model'    => 'gpt-4o',
            'gemini_model'    => 'gemini-2.0-flash',
            'max_iterations'  => '10',
            'system_prompt'   => 'You are Alfred, an AI assistant integrated into a Jeedom home automation system. You help the user control and monitor their smart home. Be concise and friendly.',
        ];
        foreach ($defaults as $key => $value) {
            if (config::byKey($key, __CLASS__) === '') {
                config::save($key, $value, __CLASS__);
            }
        }
        // Auto-detect JeedomMCP internal URL if not set
        if (config::byKey('mcp_url', __CLASS__) === '') {
            $internalUrl = network::getNetworkAccess('internal', 'proto:ip:port:comp');
            config::save('mcp_url', $internalUrl . '/plugins/jeedomMCP/api/mcp.php', __CLASS__);
        }
        // Mirror JeedomMCP API key if available and not set
        if (config::byKey('mcp_api_key', __CLASS__) === '') {
            $mcpKey = config::byKey('mcpApiKey', 'jeedomMCP');
            if ($mcpKey !== '') {
                config::save('mcp_api_key', $mcpKey, __CLASS__);
            }
        }
    }

    public static function cron() {}

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

    public static function getMcpUrl(): string {
        return (string)config::byKey('mcp_url', __CLASS__);
    }

    public static function getMcpApiKey(): string {
        return (string)config::byKey('mcp_api_key', __CLASS__);
    }

    public static function getSystemPrompt(): string {
        return (string)config::byKey('system_prompt', __CLASS__);
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
