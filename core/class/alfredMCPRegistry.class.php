<?php

/**
 * Registry of MCP servers for Alfred.
 *
 * Aggregates tools from all enabled servers and routes callTool() to
 * the correct server. Tool-name conflicts are tracked (first declared wins).
 */
class alfredMCPRegistry
{
    /** @var array{mcp: alfredMCP, original_name: string}[] tool_name => routing entry */
    private array $toolMap = [];

    /** @var array Normalized merged tool list exposed to the LLM */
    private array $tools = [];

    /** @var string[] Conflicting tool names that were ignored */
    private array $conflicts = [];

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Build a registry from the saved mcp_servers config.
     * Skips unreachable servers (logs an error event via callback if provided).
     */
    public static function fromConfig(?callable $onError = null): self
    {
        $registry = new self();
        $servers  = alfred::getMcpServers();

        foreach ($servers as $cfg) {
            if (!($cfg['enabled'] ?? true)) {
                continue;
            }

            $mcp = new alfredMCP(
                $cfg['url']         ?? '',
                $cfg['auth_header'] ?? 'X-API-Key',
                $cfg['auth_value']  ?? ''
            );

            $slug        = $cfg['slug']         ?? '';
            $prefixTools = (bool)($cfg['prefix_tools'] ?? false);

            try {
                $registry->addServer($mcp, $slug, $prefixTools);
            } catch (Exception $e) {
                if ($onError !== null) {
                    $label = $cfg['name'] ?? $cfg['url'] ?? 'unknown';
                    ($onError)("MCP server '{$label}' unavailable: " . $e->getMessage());
                }
            }
        }

        return $registry;
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
     * Returns the merged tool list to pass to the LLM.
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * Route a tool call to the correct server.
     * The $name is the exposed (possibly prefixed) name returned by listTools().
     */
    public function callTool(string $name, array $arguments)
    {
        if (!isset($this->toolMap[$name])) {
            throw new Exception("No MCP server registered for tool '{$name}'.");
        }

        $entry = $this->toolMap[$name];
        return $entry['mcp']->callTool($entry['original_name'], $arguments);
    }

    /**
     * Returns names of tools that were skipped due to naming conflicts.
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * True if no tools were loaded (all servers disabled / unreachable).
     */
    public function isEmpty(): bool
    {
        return empty($this->tools);
    }
}
