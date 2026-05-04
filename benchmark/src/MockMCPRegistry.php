<?php

/**
 * Mock MCP registry for benchmarking.
 *
 * Replaces alfredMCPRegistry: serves fixture data for info tools,
 * records every call, and returns mock success for action tools.
 * No network calls to a real Jeedom instance.
 *
 * Tool definitions are loaded from JeedomMCP/api/mcp_tools.php when
 * jeedom_mcp_path is configured, so they stay in sync with the real plugin
 * automatically. The acl_mode parameter controls which tools are exposed
 * to the model — defaults to 'read_execute' (home-assistant subset).
 */
class MockMCPRegistry
{
    /** @var array House fixture {rooms, devices, scenarios} */
    private $fixture;

    /** @var array Recorded calls [{name, input, result}] */
    private $callLog = [];

    /** @var array Optional per-tool response overrides (tool_name => response) */
    private $overrides;

    /** @var string Path to the JeedomMCP plugin repo (optional) */
    private $jeedomMcpPath;

    /**
     * ACL mode controlling which tools are visible to the model.
     *   'read_execute'  — home-assistant subset (devices, rooms, scenarios, command_execute)
     *   'full_admin'    — all tools from mcp_tools.php
     */
    private $aclMode;

    /** Tools exposed to the model for the current ACL mode */
    private static $READ_EXECUTE_TOOLS = [
        'devices_list', 'devices_states', 'device_get_commands', 'device_get_history',
        'command_execute', 'rooms_list', 'scenarios_list', 'scenario_run',
    ];

    public function __construct(
        array  $fixture,
        array  $overrides      = [],
        string $jeedomMcpPath  = '',
        string $aclMode        = 'read_execute'
    ) {
        $this->fixture        = $fixture;
        $this->overrides      = $overrides;
        $this->jeedomMcpPath  = rtrim($jeedomMcpPath, '/\\');
        $this->aclMode        = $aclMode;
    }

    // -------------------------------------------------------------------------
    // Interface — mirrors alfredMCPRegistry
    // -------------------------------------------------------------------------

    public function listTools(): array
    {
        $all = $this->loadAllTools();

        if ($this->aclMode === 'full_admin') {
            return $all;
        }

        // Default: read_execute — only expose the home-assistant subset
        return array_values(array_filter($all, function ($t) {
            return in_array($t['name'], self::$READ_EXECUTE_TOOLS, true);
        }));
    }

    public function callTool(string $name, array $arguments)
    {
        $result = isset($this->overrides[$name])
            ? $this->overrides[$name]
            : $this->dispatch($name, $arguments);

        $this->callLog[] = ['name' => $name, 'input' => $arguments, 'result' => $result];

        return $result;
    }

    public function isEmpty(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------

    public function getCallLog(): array
    {
        return $this->callLog;
    }

    public function resetCallLog(): void
    {
        $this->callLog = [];
    }

    // -------------------------------------------------------------------------
    // Tool definitions — live from JeedomMCP or built-in fallback
    // -------------------------------------------------------------------------

    private function loadAllTools(): array
    {
        if ($this->jeedomMcpPath !== '') {
            $file = $this->jeedomMcpPath . '/api/mcp_tools.php';
            if (file_exists($file)) {
                return require $file;
            }
        }
        return $this->builtInTools();
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    private function dispatch(string $name, array $args): array
    {
        switch ($name) {
            case 'devices_list':        return $this->mockDevicesList($args);
            case 'devices_states':      return $this->mockDevicesStates($args);
            case 'rooms_list':          return $this->mockRoomsList($args);
            case 'scenarios_list':      return $this->mockScenariosList($args);
            case 'command_execute':     return $this->mockCommandExecute($args);
            case 'scenario_run':        return $this->mockScenarioRun($args);
            case 'device_get_history':  return $this->mockDeviceGetHistory($args);
            case 'device_get_commands': return $this->mockDeviceGetCommands($args);
            default:
                return ['error' => "Tool '{$name}' exists but is not available in this benchmark configuration."];
        }
    }

    // -------------------------------------------------------------------------
    // Info tools — serve fixture data
    // -------------------------------------------------------------------------

    private function mockDevicesList(array $args): array
    {
        $items = $this->fixture['devices'] ?? [];

        if (!empty($args['room_ids'])) {
            $roomIds = $args['room_ids'];
            $items   = array_values(array_filter($items, function ($d) use ($roomIds) {
                return in_array($d['room_id'] ?? null, $roomIds, true);
            }));
        }

        if (!empty($args['categories'])) {
            $cats  = $args['categories'];
            $items = array_values(array_filter($items, function ($d) use ($cats) {
                return !empty(array_intersect($d['categories'] ?? [], $cats));
            }));
        }

        $includeState   = !isset($args['include_state'])   || (bool)$args['include_state'];
        $includeActions = !isset($args['include_actions']) || (bool)$args['include_actions'];

        if (!$includeState || !$includeActions) {
            $items = array_map(function ($d) use ($includeState, $includeActions) {
                if (!$includeState)   unset($d['state']);
                if (!$includeActions) unset($d['actions']);
                return $d;
            }, $items);
        }

        $total  = count($items);
        $offset = (int)($args['offset'] ?? 0);
        $limit  = (int)($args['limit']  ?? 50);
        $items  = $limit > 0
            ? array_slice($items, $offset, $limit)
            : array_slice($items, $offset);

        return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => array_values($items)];
    }

    private function mockDevicesStates(array $args): array
    {
        $ids   = $args['equipment_ids'] ?? [];
        $items = [];

        foreach ($this->fixture['devices'] ?? [] as $device) {
            if (in_array($device['id'], $ids, true)) {
                $items[] = ['id' => $device['id'], 'name' => $device['name'], 'state' => $device['state'] ?? []];
            }
        }

        return ['items' => $items];
    }

    private function mockRoomsList(array $args): array
    {
        $items  = $this->fixture['rooms'] ?? [];
        $total  = count($items);
        $offset = (int)($args['offset'] ?? 0);
        $limit  = (int)($args['limit']  ?? 50);
        $items  = $limit > 0 ? array_slice($items, $offset, $limit) : array_slice($items, $offset);

        return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => array_values($items)];
    }

    private function mockScenariosList(array $args): array
    {
        $items  = $this->fixture['scenarios'] ?? [];
        $total  = count($items);
        $offset = (int)($args['offset'] ?? 0);
        $limit  = (int)($args['limit']  ?? 50);
        $items  = $limit > 0 ? array_slice($items, $offset, $limit) : array_slice($items, $offset);

        return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => array_values($items)];
    }

    private function mockDeviceGetHistory(array $args): array
    {
        return [
            'equipment_id' => $args['equipment_id'] ?? null,
            'command_name' => $args['command_name'] ?? '',
            'values'       => [],
            'note'         => 'Mock: no historical data in benchmark mode.',
        ];
    }

    private function mockDeviceGetCommands(array $args): array
    {
        $id = $args['equipment_id'] ?? null;
        foreach ($this->fixture['devices'] ?? [] as $device) {
            if ($device['id'] === $id) {
                return ['items' => $device['actions'] ?? []];
            }
        }
        return ['items' => []];
    }

    // -------------------------------------------------------------------------
    // Action tools — record call, return mock success (or error if unknown ID)
    // -------------------------------------------------------------------------

    private function mockCommandExecute(array $args): array
    {
        // Real API format: {commands: [{id, value}, ...]}
        $commands = $args['commands'] ?? [];
        if (empty($commands)) {
            return ['success' => false, 'error' => 'commands array is required.'];
        }

        $validIds   = $this->collectCommandIds();
        $errors     = [];
        $executed   = [];

        foreach ($commands as $cmd) {
            $ids = isset($cmd['ids']) ? (array)$cmd['ids'] : [(int)($cmd['id'] ?? 0)];
            foreach ($ids as $id) {
                if (in_array((int)$id, $validIds, true)) {
                    $executed[] = $id;
                } else {
                    $errors[] = "Command ID {$id} does not exist in this Jeedom instance.";
                }
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors)];
        }

        return ['success' => true, 'executed' => $executed];
    }

    private function mockScenarioRun(array $args): array
    {
        $id = $args['scenario_id'] ?? null;

        foreach ($this->fixture['scenarios'] ?? [] as $scenario) {
            if ((int)$scenario['id'] === (int)$id) {
                return ['success' => true, 'message' => "Scenario {$id} started (mock)."];
            }
        }

        return [
            'success' => false,
            'error'   => "Scenario ID {$id} does not exist in this Jeedom instance.",
        ];
    }

    private function collectCommandIds(): array
    {
        $ids = [];
        foreach ($this->fixture['devices'] ?? [] as $device) {
            foreach ($device['actions'] ?? [] as $action) {
                $ids[] = (int)$action['id'];
            }
        }
        return $ids;
    }

    // -------------------------------------------------------------------------
    // Built-in fallback tool definitions (used when jeedom_mcp_path is not set)
    // These should match JeedomMCP/api/mcp_tools.php — read_execute subset only.
    // -------------------------------------------------------------------------

    private function builtInTools(): array
    {
        return [
            [
                'name'        => 'devices_list',
                'description' => 'List Jeedom equipment with their current state and available actions. By default only enabled devices are returned — use include_inactive=true to also include disabled ones. Use with_plugin_info=true to also get managed_by (plugin name, e.g. "openzwave") and plugin_id (plugin-internal identifier, e.g. the Z-Wave node ID). Use include_state=false or include_actions=false to reduce response size when only metadata is needed.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'categories'         => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default']], 'description' => 'Filter by category — returns equipment matching at least one.'],
                        'room_ids'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Filter by room — returns only equipment whose room_id is in this list.'],
                        'managed_by'         => ['type' => 'string',  'description' => 'Filter by plugin name (e.g. "openzwave"). Only used when with_plugin_info=true.'],
                        'with_plugin_info'   => ['type' => 'boolean', 'description' => 'If true, include managed_by (plugin name) and plugin_id for each device. Default false.'],
                        'include_hidden'     => ['type' => 'boolean', 'description' => 'Include devices hidden in the Jeedom UI (is_visible=false). Default false.'],
                        'include_inactive'   => ['type' => 'boolean', 'description' => 'Include disabled devices (is_active=false). Default false.'],
                        'include_state'      => ['type' => 'boolean', 'description' => 'Include the state map for each device (default true).'],
                        'include_actions'    => ['type' => 'boolean', 'description' => 'Include the actions array for each device (default true).'],
                        'include_historical' => ['type' => 'boolean', 'description' => 'Include an "available_historical" array listing the names of historized info commands. Default false.'],
                        'limit'              => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                        'offset'             => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'devices_states',
                'description' => 'Bulk refresh the state of equipment. Provide equipment_ids for specific devices, or use categories/room_ids to match devices without prior discovery. Returns {id, state} per device.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'List of specific equipment IDs to refresh.'],
                        'categories'    => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default']], 'description' => 'Filter by category.'],
                        'room_ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Filter by room.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'command_execute',
                'description' => 'Execute one or more action commands with optional per-command values. Use {id, value} for a single command, {ids, value} to share a value across several commands. Returns {"success": true} on success. To read updated device states after execution, use devices_states.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'commands' => [
                            'type'        => 'array',
                            'description' => 'List of commands to execute. Each entry has either "id" (int) or "ids" (int[]), plus an optional "value" string.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'    => ['type' => 'integer', 'description' => 'Single command ID.'],
                                    'ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Multiple command IDs sharing the same value.'],
                                    'value' => ['type' => 'string', 'description' => 'Value for slider, color or message subTypes.'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['commands'],
                ],
            ],
            [
                'name'        => 'rooms_list',
                'description' => 'List all rooms (objects) in the Jeedom home. Returns a paginated response.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'scenarios_list',
                'description' => 'List all Jeedom scenarios. Returns a paginated response.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'scenario_run',
                'description' => 'Trigger a Jeedom scenario.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.'],
                    ],
                    'required' => ['scenario_id'],
                ],
            ],
            [
                'name'        => 'device_get_history',
                'description' => 'Query the history of a device command (sensor values, power consumption, states…). Use devices_list with include_historical=true to discover command names available for history queries.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID (from devices_list).'],
                        'command_name' => ['type' => 'string',  'description' => 'Name of the historized command (from the available_historical array in devices_list).'],
                        'start'        => ['type' => 'string',  'description' => 'Start datetime, ISO 8601 or YYYY-MM-DD. Defaults to 7 days ago.'],
                        'end'          => ['type' => 'string',  'description' => 'End datetime, ISO 8601 or YYYY-MM-DD. Defaults to now.'],
                        'aggregate'    => ['type' => 'string',  'description' => '"stats" (default): single summary. "avg"/"min"/"max"/"sum": time series. "raw": all individual points.', 'enum' => ['raw', 'stats', 'avg', 'min', 'max', 'sum']],
                        'group_by'     => ['type' => 'string',  'description' => 'Time bucket for series aggregates.', 'enum' => ['hour', 'day']],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'device_get_commands',
                'description' => 'Return all commands (info and action) for a given equipment, with their IDs, names, types, and subTypes.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID obtained from devices_list.'],
                        'type'         => ['type' => 'string',  'description' => 'Filter by command type. Omit to return all commands.', 'enum' => ['info', 'action']],
                    ],
                    'required' => ['equipment_id'],
                ],
            ],
        ];
    }
}
