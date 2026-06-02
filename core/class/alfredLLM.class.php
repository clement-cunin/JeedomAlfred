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

    abstract public function getProvider(): string;

    public function getModel(): string
    {
        return $this->model;
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
            throw new Exception("HTTP request failed [{$url}]: {$err}");
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

class alfredLLMChain extends alfredLLMAdapter
{
    /** @var alfredLLMAdapter[] */
    private $adapters;

    public function __construct(array $adapters)
    {
        if (empty($adapters)) {
            throw new Exception('alfredLLMChain requires at least one adapter.');
        }
        parent::__construct('', '');
        $this->adapters = $adapters;
    }

    public function getProvider(): string
    {
        return implode(',', array_map(function ($a) { return $a->getProvider(); }, $this->adapters));
    }

    public function getModel(): string
    {
        return $this->adapters[0]->getModel();
    }

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $lastException = null;
        foreach ($this->adapters as $i => $adapter) {
            try {
                $result = $adapter->chat($messages, $tools, $systemPrompt);
                if ($i > 0) {
                    log::add('alfred', 'info', 'LLM chain: fallback to ' . $adapter->getProvider() . ' succeeded after ' . $i . ' failure(s)');
                }
                return $result;
            } catch (Exception $e) {
                $lastException = $e;
                if (!self::isRetriable($e)) {
                    throw $e;
                }
                log::add('alfred', 'warning', 'LLM chain: ' . $adapter->getProvider() . ' failed (retriable): ' . $e->getMessage());
            }
        }
        throw new Exception('All LLM providers failed. Last error: ' . $lastException->getMessage());
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $lastException = null;
        foreach ($this->adapters as $i => $adapter) {
            $emitted = false;
            $wrapped = function (string $chunk) use (&$emitted, $onDelta) {
                $emitted = true;
                $onDelta($chunk);
            };
            try {
                $result = $adapter->chatStream($messages, $tools, $systemPrompt, $wrapped);
                if ($i > 0) {
                    log::add('alfred', 'info', 'LLM chain: stream fallback to ' . $adapter->getProvider() . ' succeeded after ' . $i . ' failure(s)');
                }
                return $result;
            } catch (Exception $e) {
                $lastException = $e;
                if ($emitted || !self::isRetriable($e)) {
                    throw $e;
                }
                log::add('alfred', 'warning', 'LLM chain: ' . $adapter->getProvider() . ' stream failed (retriable, no data emitted): ' . $e->getMessage());
            }
        }
        throw new Exception('All LLM providers failed. Last error: ' . $lastException->getMessage());
    }

    public function testConnection(): array
    {
        return $this->adapters[0]->testConnection();
    }

    public function listModels(): array
    {
        return $this->adapters[0]->listModels();
    }

    private static function isRetriable(Exception $e): bool
    {
        $msg = $e->getMessage();
        // Curl-level: timeout, connection refused, network unreachable
        if (strpos($msg, 'HTTP request failed') !== false) return true;
        // HTTP 429 rate limit, 502 bad gateway, 503 unavailable, 504 timeout
        if (preg_match('/\b(429|502|503|504)\b/', $msg)) return true;
        return false;
    }
}

// =============================================================================

class alfredLLM
{
    /**
     * Build the configured LLM adapter (or chain of adapters for fallback).
     * If $provider is given explicitly, bypass chain and return a single adapter
     * (used by testLLM, listModels, etc.).
     */
    public static function make(string $provider = '', string $apiKey = '', string $model = ''): alfredLLMAdapter
    {
        if ($provider !== '') {
            return self::makeAdapter($provider, $apiKey, $model);
        }

        $chain = alfred::getProviderChain();
        if (empty($chain)) {
            $chain = [alfred::getProvider()];
        }

        if (count($chain) === 1) {
            return self::makeAdapter($chain[0]);
        }

        $adapters = [];
        foreach ($chain as $slug) {
            try {
                $adapters[] = self::makeAdapter($slug);
            } catch (Exception $e) {
                log::add('alfred', 'warning', "LLM chain: skipping '{$slug}' (not configured): " . $e->getMessage());
            }
        }

        if (empty($adapters)) {
            throw new Exception('No LLM provider is configured. Please set an API key in the plugin settings.');
        }

        return count($adapters) === 1 ? $adapters[0] : new alfredLLMChain($adapters);
    }

    private static function makeAdapter(string $provider, string $apiKey = '', string $model = ''): alfredLLMAdapter
    {
        if ($apiKey === '') $apiKey = alfred::getApiKey($provider);
        if ($model  === '') $model  = alfred::getModel($provider);

        switch ($provider) {
            case 'mistral':
                if ($apiKey === '') throw new Exception("No API key configured for Mistral.");
                require_once __DIR__ . '/alfredLLMMistralAdapter.class.php';
                return new alfredLLMMistralAdapter($apiKey, $model);
            case 'gemini':
                if ($apiKey === '') throw new Exception("No API key configured for Gemini.");
                require_once __DIR__ . '/alfredLLMGeminiAdapter.class.php';
                return new alfredLLMGeminiAdapter($apiKey, $model);
            case 'ollama':
                if ($apiKey === '') $apiKey = alfred::getBaseUrl('ollama');
                if ($apiKey === '') $apiKey = 'http://localhost:11434';
                require_once __DIR__ . '/alfredLLMOllamaAdapter.class.php';
                return new alfredLLMOllamaAdapter($apiKey, $model);
            default:
                throw new Exception("Unknown LLM provider: '{$provider}'.");
        }
    }
}
