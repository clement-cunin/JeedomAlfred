<?php

/**
 * Registry of MCP servers for Alfred.
 *
 * Servers are known from config but their tools are NOT loaded eagerly: a server's tool
 * schemas are only fetched and merged in once it is activated (see activateServer()). This
 * keeps the default tool list handed to the LLM small — see listServerSummaries() for the
 * short name+description list that lets the LLM decide which server to activate.
 *
 * Tool-name conflicts are tracked (first declared wins).
 */
class alfredMCPRegistry
{
    /** @var array{mcp: alfredMCP, original_name: string}[] tool_name => routing entry */
    private array $toolMap = [];

    /** @var array Normalized merged tool list exposed to the LLM (activated servers only) */
    private array $tools = [];

    /** @var string[] Conflicting tool names that were ignored */
    private array $conflicts = [];

    /** @var array key => ['cfg' => array, 'loaded' => bool] every known enabled server, keyed by a stable key */
    private array $servers = [];

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Build a registry from the saved mcp_servers config.
     * Records every enabled server's config but does NOT contact any server —
     * tools are only fetched when a server is activated via activateServer().
     */
    public static function fromConfig(): self
    {
        $registry = new self();
        $servers  = alfred::getMcpServers();

        $usedKeys = [];
        foreach ($servers as $index => $cfg) {
            if (!($cfg['enabled'] ?? true)) {
                continue;
            }

            $key             = self::resolveServerKey($cfg, $index, $usedKeys);
            $usedKeys[$key]  = true;
            $registry->servers[$key] = ['cfg' => $cfg, 'loaded' => false];
        }

        return $registry;
    }

    /**
     * Derives a stable, unique key identifying a configured server (used by the LLM to
     * activate it). Prefers the explicit "slug" field, falls back to a slugified "name",
     * then to a positional key. Never affects tool-name prefixing (see addServer()).
     */
    private static function resolveServerKey(array $cfg, int $index, array $usedKeys): string
    {
        $base = trim((string)($cfg['slug'] ?? ''));
        if ($base === '') {
            $name = trim((string)($cfg['name'] ?? ''));
            $base = $name !== '' ? trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)), '_') : '';
        }
        if ($base === '') {
            $base = 'server_' . $index;
        }

        $key = $base;
        $n   = 2;
        while (isset($usedKeys[$key])) {
            $key = $base . '_' . $n;
            $n++;
        }
        return $key;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Add a server and merge its tools into the registry.
     * Throws if the server is unreachable (listTools fails).
     */
    public function addServer(alfredMCP $mcp, string $slug = '', bool $prefixTools = false): void
    {
        $rawTools = $mcp->listTools();

        foreach ($rawTools as $tool) {
            $originalName = $tool['name'];
            $exposedName  = ($prefixTools && $slug !== '')
                ? $slug . '__' . $originalName
                : $originalName;

            if (isset($this->toolMap[$exposedName])) {
                $this->conflicts[] = $exposedName;
                continue; // first declared wins
            }

            $this->toolMap[$exposedName] = [
                'mcp'           => $mcp,
                'original_name' => $originalName,
            ];

            $this->tools[] = array_merge($tool, ['name' => $exposedName]);
        }
    }

    /**
     * Returns short summaries (key, name, description, active) of every enabled configured
     * server. Used to build the discovery list shown to the LLM — never triggers a network call.
     */
    public function listServerSummaries(): array
    {
        $out = [];
        foreach ($this->servers as $key => $entry) {
            $cfg   = $entry['cfg'];
            $out[] = [
                'key'         => $key,
                'name'        => (string)($cfg['name'] ?? $key),
                'description' => (string)($cfg['description'] ?? ''),
                'active'      => $entry['loaded'],
            ];
        }
        return $out;
    }

    /**
     * True if $key refers to a known (enabled, configured) server.
     */
    public function hasServer(string $key): bool
    {
        return isset($this->servers[$key]);
    }

    /**
     * True if $key's tools have already been loaded into this registry.
     */
    public function isServerActive(string $key): bool
    {
        return isset($this->servers[$key]) && $this->servers[$key]['loaded'];
    }

    /**
     * Activate a configured MCP server on demand: fetches and merges its tools into the
     * registry. Returns the list of newly exposed tool definitions (empty if already active).
     * Throws if $key is unknown or the server is unreachable.
     */
    public function activateServer(string $key): array
    {
        if (!isset($this->servers[$key])) {
            throw new Exception("Unknown MCP server '{$key}'.");
        }
        if ($this->servers[$key]['loaded']) {
            return [];
        }

        $cfg = $this->servers[$key]['cfg'];
        $mcp = new alfredMCP(
            $cfg['url']         ?? '',
            $cfg['auth_header'] ?? 'X-API-Key',
            $cfg['auth_value']  ?? ''
        );

        $before = array_column($this->tools, 'name');
        $this->addServer($mcp, (string)($cfg['slug'] ?? ''), (bool)($cfg['prefix_tools'] ?? false));
        $this->servers[$key]['loaded'] = true;

        return array_values(array_filter($this->tools, function ($t) use ($before) {
            return !in_array($t['name'], $before, true);
        }));
    }

    /**
     * Returns the merged tool list to pass to the LLM (activated servers only).
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * Route a tool call to the correct server.
     * The $name is the exposed (possibly prefixed) name returned by listTools().
     */
    public function callTool(string $name, array $arguments, ?string $sessionId = null)
    {
        if (!isset($this->toolMap[$name])) {
            throw new Exception("No MCP server registered for tool '{$name}'.");
        }

        $entry = $this->toolMap[$name];
        return $entry['mcp']->callTool($entry['original_name'], $arguments, $sessionId);
    }

    /**
     * Returns names of tools that were skipped due to naming conflicts.
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * True if no tools were loaded (no server activated yet, or all unreachable).
     */
    public function isEmpty(): bool
    {
        return empty($this->tools);
    }
}
