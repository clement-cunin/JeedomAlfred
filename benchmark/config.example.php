<?php
/**
 * Benchmark configuration — copy this file to config.php and fill in your keys.
 * config.php is gitignored.
 */
return [

    // ── LLM provider API keys ─────────────────────────────────────────────────

    'providers' => [
        'mistral' => [
            'api_key' => '',  // https://console.mistral.ai/api-keys
        ],
        'gemini' => [
            'api_key' => '',  // https://aistudio.google.com/app/apikey
        ],
        'anthropic' => [
            'api_key' => '',  // https://console.anthropic.com/settings/keys
        ],
        'openai' => [
            'api_key' => '',  // https://platform.openai.com/api-keys
        ],
        'ollama' => [
            'api_key' => 'http://localhost:11434',  // base URL of your Ollama instance
        ],
    ],

    // ── Default system prompt ─────────────────────────────────────────────────
    // Leave empty to use the built-in benchmark default.
    // Paste your real Alfred system_prompt here to maximise fidelity.

    'system_prompt' => '',

    // ── JeedomMCP tool definitions (optional) ────────────────────────────────
    // Absolute path to the JeedomMCP plugin repository.
    // When set, MockMCPRegistry loads tool schemas directly from
    // JeedomMCP/api/mcp_tools.php so they stay in sync with the real plugin.
    // Leave empty to use the built-in fallback (read_execute subset only).

    'jeedom_mcp_path' => '',   // e.g. 'C:/Users/you/workspace/JeedomMCP'

    // ── ACL mode ─────────────────────────────────────────────────────────────
    // Controls which tools are exposed to the model during benchmarks.
    //   'read_execute'  — home-assistant subset: devices, rooms, scenarios,
    //                     command_execute, scenario_run (default)
    //   'full_admin'    — all tools from mcp_tools.php (admin, plugins, logs…)

    'acl_mode' => 'read_execute',

];
