<?php

/**
 * Lightweight MCP client for Alfred.
 * Talks to an MCP server over streamable-http (JSON-RPC 2.0 over HTTP POST).
 *
 * Usage:
 *   $mcp   = new alfredMCP($url, 'X-API-Key', $apiKey);
 *   $tools = $mcp->listTools();
 *   $result = $mcp->callTool('devices_list', []);
 */
class alfredMCP
{
    private string $url;
    private string $authHeader;
    private string $authValue;
    private int    $timeout;
    private ?array $cachedTools = null;

    public function __construct(
        string $url        = '',
        string $authHeader = 'X-API-Key',
        string $authValue  = '',
        int    $timeout    = 20
    ) {
        $this->url        = $url;
        $this->authHeader = $authHeader !== '' ? $authHeader : 'X-API-Key';
        $this->authValue  = $authValue;
        $this->timeout    = $timeout;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the list of available MCP tools.
     * Result is cached for the lifetime of this instance.
     */
    public function listTools(): array
    {
        if ($this->cachedTools !== null) {
            return $this->cachedTools;
        }

        $response = $this->send('tools/list', new stdClass());
        $tools = $response['tools'] ?? [];

        // Normalize: keep name, description, inputSchema
        $this->cachedTools = array_map(function ($t) {
            return [
                'name'        => $t['name'],
                'description' => $t['description'] ?? '',
                'inputSchema' => $t['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            ];
        }, $tools);

        return $this->cachedTools;
    }

    /**
     * Call a single MCP tool and return its result as a PHP value.
     */
    public function callTool(string $name, array $arguments, ?string $sessionId = null)
    {
        $response = $this->send('tools/call', [
            'name'      => $name,
            'arguments' => $arguments,
        ], $sessionId);

        // MCP tool result: {content: [{type:'text', text:'...'}], isError: bool}
        if (!empty($response['isError'])) {
            $errText = $this->extractText($response['content'] ?? []);
            throw new Exception("MCP tool '{$name}' returned an error: {$errText}");
        }

        $text = $this->extractText($response['content'] ?? []);

        // Try to decode JSON (most MCP tools return JSON strings)
        $decoded = json_decode($text, true);
        return $decoded !== null ? $decoded : $text;
    }

    // -------------------------------------------------------------------------
    // JSON-RPC transport
    // -------------------------------------------------------------------------

    private function send(string $method, $params, ?string $sessionId = null): array
    {
        if ($this->url === '') {
            throw new Exception('MCP server URL is not configured.');
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->authHeader !== '' && $this->authValue !== '') {
            $headers[] = $this->authHeader . ': ' . $this->authValue;
        }
        if ($sessionId !== null && $sessionId !== '') {
            $headers[] = 'X-Alfred-Session-Id: ' . $sessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception("MCP HTTP request failed [{$this->url}]: {$err}");
        }

        // Strip UTF-8 BOM if present (some PHP setups emit it)
        $raw  = ltrim($raw, "\xEF\xBB\xBF");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Exception("MCP invalid JSON response (HTTP {$code}): " . substr($raw, 0, 200));
        }
        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? json_encode($data['error']);
            throw new Exception("MCP error: {$msg}");
        }

        return $data['result'] ?? [];
    }

    private function extractText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'];
            }
        }
        return implode("\n", $parts);
    }
}
