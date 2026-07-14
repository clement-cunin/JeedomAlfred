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
            throw new Exception("API error (HTTP {$code}) [{$url}]: {$msg}");
        }
        return $data;
    }
}

// =============================================================================

/**
 * Chain of LLM adapters with automatic fallback and per-entry auto-disable.
 *
 * Each entry is ['id' => string, 'adapter' => alfredLLMAdapter].
 * On a retriable failure, the entry is temp-disabled for 15 minutes via alfred::tempDisableProvider().
 */
class alfredLLMChain extends alfredLLMAdapter
{
    /** @var array [['id' => string, 'adapter' => alfredLLMAdapter], ...] */
    private $entries;

    public function __construct(array $entries)
    {
        if (empty($entries)) {
            throw new Exception('alfredLLMChain requires at least one entry.');
        }
        parent::__construct('', '');
        $this->entries = $entries;
    }

    public function getProvider(): string
    {
        return implode(',', array_map(function ($e) { return $e['adapter']->getProvider(); }, $this->entries));
    }

    public function getModel(): string
    {
        return $this->entries[0]['adapter']->getModel();
    }

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $lastException = null;
        foreach ($this->entries as $i => $entry) {
            try {
                $result = $entry['adapter']->chat($messages, $tools, $systemPrompt);
                if ($i > 0) {
                    log::add('alfred', 'info', 'LLM chain: fallback to ' . $entry['adapter']->getProvider() . ' succeeded after ' . $i . ' failure(s)');
                }
                $result['_provider'] = $entry['adapter']->getProvider();
                $result['_model']    = $entry['adapter']->getModel();
                return $result;
            } catch (Exception $e) {
                $lastException = $e;
                if (!self::isRetriable($e)) throw $e;
                if (!empty($entry['id'])) {
                    alfred::tempDisableProvider($entry['id'], 15 * 60);
                }
                log::add('alfred', 'warning', 'LLM chain: ' . $entry['adapter']->getProvider() . ' failed (retriable, auto-disabled 15min): ' . $e->getMessage());
            }
        }
        throw new Exception('All LLM providers failed. Last error: ' . $lastException->getMessage());
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $lastException = null;
        foreach ($this->entries as $i => $entry) {
            $emitted = false;
            $wrapped = function (string $chunk) use (&$emitted, $onDelta) {
                $emitted = true;
                $onDelta($chunk);
            };
            try {
                $result = $entry['adapter']->chatStream($messages, $tools, $systemPrompt, $wrapped);
                if ($i > 0) {
                    log::add('alfred', 'info', 'LLM chain: stream fallback to ' . $entry['adapter']->getProvider() . ' succeeded after ' . $i . ' failure(s)');
                }
                $result['_provider'] = $entry['adapter']->getProvider();
                $result['_model']    = $entry['adapter']->getModel();
                return $result;
            } catch (Exception $e) {
                $lastException = $e;
                if ($emitted || !self::isRetriable($e)) throw $e;
                if (!empty($entry['id'])) {
                    alfred::tempDisableProvider($entry['id'], 15 * 60);
                }
                log::add('alfred', 'warning', 'LLM chain: ' . $entry['adapter']->getProvider() . ' stream failed (retriable, auto-disabled 15min): ' . $e->getMessage());
            }
        }
        throw new Exception('All LLM providers failed. Last error: ' . $lastException->getMessage());
    }

    public function testConnection(): array { return $this->entries[0]['adapter']->testConnection(); }
    public function listModels(): array     { return $this->entries[0]['adapter']->listModels(); }

    private static function isRetriable(Exception $e): bool
    {
        $msg = $e->getMessage();
        if (strpos($msg, 'HTTP request failed') !== false) return true;
        if (preg_match('/\b(429|502|503|504)\b/', $msg)) return true;
        if (stripos($msg, 'expired') !== false && stripos($msg, 'token') !== false) return true;
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
    /**
     * Whether at least one entry in the provider chain has the credential it
     * needs (base_url for ollama, api_key for the rest). Unlike make(), this
     * never instantiates an adapter or makes a network call — safe to use for
     * a cheap "is Alfred usable at all" UI check.
     */
    public static function hasConfiguredProvider(): bool
    {
        $chain = self::normalizeChain(alfred::getProviderChain());
        foreach ($chain as $entry) {
            if (isset($entry['enabled']) && !$entry['enabled']) continue;
            if (self::entryCredential($entry) !== '') return true;
        }
        return false;
    }

    public static function make(string $provider = '', string $apiKey = '', string $model = ''): alfredLLMAdapter
    {
        if ($provider !== '') {
            return self::makeAdapter($provider, $apiKey, $model);
        }

        $chain = self::normalizeChain(alfred::getProviderChain());

        $active = array_values(array_filter($chain, function ($entry) {
            if (isset($entry['enabled']) && !$entry['enabled']) return false;
            if (!empty($entry['id']) && alfred::isTempDisabled($entry['id'])) return false;
            return true;
        }));

        if (empty($active)) {
            throw new Exception('No LLM provider available (all are disabled or temporarily unavailable).');
        }

        if (count($active) === 1) {
            $e = $active[0];
            return self::makeAdapter($e['type'], self::entryCredential($e), $e['model'] ?? '');
        }

        $entries = [];
        foreach ($active as $entry) {
            try {
                $adapter   = self::makeAdapter($entry['type'], self::entryCredential($entry), $entry['model'] ?? '');
                $entries[] = ['id' => $entry['id'] ?? '', 'adapter' => $adapter];
            } catch (Exception $e) {
                log::add('alfred', 'warning', "LLM chain: skipping '{$entry['type']}' (not configured): " . $e->getMessage());
            }
        }

        if (empty($entries)) {
            throw new Exception('No LLM provider is configured. Please set credentials in the plugin settings.');
        }

        return count($entries) === 1 ? $entries[0]['adapter'] : new alfredLLMChain($entries);
    }

    private static function normalizeChain(array $chain): array
    {
        if (empty($chain) || !is_string($chain[0])) return $chain;
        // Old slug-array format — normalize in-memory (permanent migration runs in activate())
        return array_map(function ($slug) {
            $entry = ['id' => '', 'type' => $slug, 'enabled' => true];
            if ($slug === 'ollama') {
                $entry['base_url'] = alfred::getBaseUrl('ollama') ?: 'http://localhost:11434';
            } else {
                $entry['api_key'] = alfred::getApiKey($slug);
            }
            $entry['model'] = alfred::getModel($slug);
            return $entry;
        }, $chain);
    }

    private static function entryCredential(array $entry): string
    {
        return ($entry['type'] === 'ollama')
            ? ($entry['base_url'] ?? '')
            : ($entry['api_key'] ?? '');
    }

    private static function makeAdapter(string $provider, string $apiKey = '', string $model = ''): alfredLLMAdapter
    {
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
                if ($apiKey === '') $apiKey = 'http://localhost:11434';
                require_once __DIR__ . '/alfredLLMOllamaAdapter.class.php';
                return new alfredLLMOllamaAdapter($apiKey, $model);
            default:
                throw new Exception("Unknown LLM provider: '{$provider}'.");
        }
    }
}
