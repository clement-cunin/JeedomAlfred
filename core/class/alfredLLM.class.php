<?php

/**
 * Alfred LLM abstraction layer.
 *
 * Internal message format (canonical, used everywhere in Alfred):
 *   [
 *     {role: 'user',      content: 'string'},
 *     {role: 'assistant', content: 'string', tool_calls: [{id, name, input: {}}]},
 *     {role: 'tool',      tool_call_id: 'id', name: 'tool_name', content: 'json_string'},
 *   ]
 *
 * Each adapter converts to/from its provider's native format.
 *
 * callTool() return value (normalized):
 *   [
 *     'text'       => string,           // assistant text (may be empty during tool use)
 *     'tool_calls' => [                 // empty if stop_reason != 'tool_use'
 *       ['id' => string, 'name' => string, 'input' => array],
 *     ],
 *     'stop_reason' => 'end_turn'|'tool_use',
 *   ]
 */
abstract class alfredLLMAdapter
{
    protected string $apiKey;
    protected string $model;

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    /**
     * Send a conversation turn to the LLM.
     *
     * @param array  $messages     Internal format messages array
     * @param array  $tools        MCP tool definitions [{name, description, inputSchema}]
     * @param string $systemPrompt System prompt
     * @return array Normalized response {text, tool_calls, stop_reason}
     */
    abstract public function chat(array $messages, array $tools, string $systemPrompt): array;

    /**
     * Stream a conversation turn, calling $onDelta for each text chunk as it arrives.
     *
     * @param array    $messages     Internal format messages array
     * @param array    $tools        MCP tool definitions [{name, description, inputSchema}]
     * @param string   $systemPrompt System prompt
     * @param callable $onDelta      Called with each text chunk: $onDelta(string $chunk)
     * @return array Normalized response {text, tool_calls, stop_reason} (same as chat())
     */
    abstract public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array;

    /**
     * Check that the API key and model are valid (lightweight call).
     * Returns ['ok' => true] or throws an Exception.
     */
    abstract public function testConnection(): array;

    /**
     * Return the list of models available for this provider and API key.
     * Returns [{id: string, name: string}] sorted by id.
     */
    abstract public function listModels(): array;

    // -------------------------------------------------------------------------
    // Shared HTTP helper
    // -------------------------------------------------------------------------

    protected function httpPost(string $url, array $headers, array $body, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception("HTTP request failed: {$err}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Exception("Invalid JSON response (HTTP {$code}): " . substr($raw, 0, 200));
        }
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? $data['error']['status'] ?? json_encode($data);
            throw new Exception("API error (HTTP {$code}): {$msg}");
        }
        return $data;
    }
}

// =============================================================================

class alfredLLM
{
    /**
     * Instantiate the right adapter for the configured provider.
     */
    public static function make(string $provider = '', string $apiKey = '', string $model = ''): alfredLLMAdapter
    {
        if ($provider === '') $provider = alfred::getProvider();
        if ($apiKey   === '') $apiKey   = alfred::getApiKey($provider);
        if ($model    === '') $model    = alfred::getModel($provider);

        if ($apiKey === '') {
            throw new Exception("No API key configured for provider '{$provider}'.");
        }

        switch ($provider) {
            case 'mistral':
                require_once __DIR__ . '/alfredLLMMistralAdapter.class.php';
                return new alfredLLMMistralAdapter($apiKey, $model);
            case 'gemini':
                require_once __DIR__ . '/alfredLLMGeminiAdapter.class.php';
                return new alfredLLMGeminiAdapter($apiKey, $model);
            default:
                throw new Exception("Unknown LLM provider: '{$provider}'.");
        }
    }
}
