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
    private string  $url;
    private string  $authHeader;
    private string  $authValue;
    private int     $timeout;
    private ?array  $cachedTools   = null;
    private bool    $initialized   = false;
    private ?string $mcpSessionId  = null;

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

        $this->ensureInitialized();
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
        try {
            $this->ensureInitialized();
            $response = $this->send('tools/call', [
                'name'      => $name,
                'arguments' => $arguments,
            ], $sessionId);
        } catch (Exception $e) {
            // Transport/protocol failures (network, HTTP status, malformed JSON-RPC)
            // don't otherwise mention which tool was being called — name it here.
            throw new Exception("MCP tool '{$name}' call failed: {$e->getMessage()}");
        }

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

    /**
     * MCP Streamable HTTP handshake: initialize → capture Mcp-Session-Id → notifications/initialized.
     * Servers that don't use sessions ignore the initialize or return no session header — no impact.
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        try {
            $responseHeaders = [];
            $this->sendRaw(
                json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => 0,
                    'method'  => 'initialize',
                    'params'  => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities'    => new stdClass(),
                        'clientInfo'      => ['name' => 'alfred', 'version' => '1.0'],
                    ],
                ]),
                $this->buildHeaders(),
                $responseHeaders
            );

            foreach ($responseHeaders as $header) {
                if (stripos($header, 'Mcp-Session-Id:') === 0) {
                    $this->mcpSessionId = trim(substr($header, strlen('Mcp-Session-Id:')));
                    break;
                }
            }

            $notifHeaders = $this->buildHeaders();
            if ($this->mcpSessionId !== null) {
                $notifHeaders[] = 'Mcp-Session-Id: ' . $this->mcpSessionId;
            }
            $this->sendRaw(
                json_encode([
                    'jsonrpc' => '2.0',
                    'method'  => 'notifications/initialized',
                    'params'  => new stdClass(),
                ]),
                $notifHeaders,
                $ignored
            );
        } catch (Exception $e) {
            // Server doesn't require initialization — proceed without session
        }
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];
        if ($this->authHeader !== '' && $this->authValue !== '') {
            $headers[] = $this->authHeader . ': ' . $this->authValue;
        }
        return $headers;
    }

    /** Low-level curl POST; populates $responseHeaders by reference. */
    private function sendRaw(string $payload, array $requestHeaders, ?array &$responseHeaders): string
    {
        $responseHeaders = [];
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $responseHeaders[] = $header;
                return strlen($header);
            },
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception("MCP HTTP request failed [{$this->url}]: {$err}");
        }
        if ($code >= 400) {
            $preview = substr(trim($raw), 0, 200);
            throw new Exception("MCP server HTTP {$code} [{$this->url}]: {$preview}");
        }
        return $raw;
    }

    private function send(string $method, $params, ?string $sessionId = null): array
    {
        if ($this->url === '') {
            throw new Exception('MCP server URL is not configured.');
        }

        $headers = $this->buildHeaders();
        if ($this->mcpSessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->mcpSessionId;
        }
        if ($sessionId !== null && $sessionId !== '') {
            $headers[] = 'X-Alfred-Session-Id: ' . $sessionId;
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);

        $ignored = null;
        $raw  = $this->sendRaw($payload, $headers, $ignored);
        $code = 0;

        // Strip UTF-8 BOM if present (some PHP setups emit it)
        $raw  = ltrim($raw, "\xEF\xBB\xBF");

        // Some MCP servers respond with SSE (text/event-stream) instead of plain JSON.
        // Extract the JSON payload from the last non-empty "data:" line.
        $body = $raw;
        if (str_contains($raw, 'data:')) {
            foreach (array_reverse(explode("\n", $raw)) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data:')) {
                    $body = trim(substr($line, 5));
                    break;
                }
            }
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $preview = substr($raw, 0, 1000) . (strlen($raw) > 1000 ? '...' : '');
            throw new Exception("MCP invalid JSON response: " . $preview);
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
