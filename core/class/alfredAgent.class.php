<?php

/**
 * Alfred ReAct agent loop.
 *
 * Orchestrates:
 *   - alfredLLM   : sends messages to the LLM, gets back text or tool calls
 *   - alfredMCP   : executes tool calls against JeedomMCP
 *   - alfredConversation : persists every turn
 *
 * The caller supplies an optional $onEvent callback to receive real-time events:
 *   $onEvent(string $type, array $data)
 *
 * Event types:
 *   tool_call  — agent is about to call a tool   {name, input}
 *   tool_result — tool returned a result          {name, result}
 *   delta      — LLM text chunk                   {text}
 *   done       — turn complete                    {text, iterations}
 *   error      — fatal error                      {message}
 *
 * Synthetic tools (handled locally, never forwarded to MCP):
 *   alfred_schedule — schedule a deferred re-invocation of the agent
 */
class alfredAgent
{
    private alfredLLMAdapter $llm;
    private alfredMCP        $mcp;
    private int              $maxIterations;
    private string           $systemPrompt;
    private ?string          $userLogin;
    private ?string          $userProfil;
    /** @var callable|null */
    private $onEvent;

    public function __construct(
        ?alfredLLMAdapter $llm             = null,
        ?alfredMCP        $mcp             = null,
        ?callable         $onEvent         = null,
        ?string           $userLogin       = null,
        ?string           $userProfil      = null,
        int               $extraIterations = 0
    ) {
        $this->llm           = $llm   ?? alfredLLM::make();
        $this->mcp           = $mcp   ?? new alfredMCP();
        $this->maxIterations = alfred::getMaxIterations() + $extraIterations;
        $this->systemPrompt  = alfred::getSystemPrompt();
        $this->onEvent       = $onEvent;
        $this->userLogin     = $userLogin;
        $this->userProfil    = $userProfil;
    }

    // -------------------------------------------------------------------------
    // Synthetic tool definitions
    // -------------------------------------------------------------------------

    /**
     * Returns the alfred_schedule tool schema in Alfred's canonical format.
     * It is injected alongside MCP tools so the LLM can discover and call it.
     */
    private static function scheduleTool(): array
    {
        return [
            'name'        => 'alfred_schedule',
            'description' => 'Schedule a deferred instruction to be executed after a delay.'
                           . ' Use this when the user asks to do something "in N minutes/hours".'
                           . ' Alfred will re-invoke itself at the right time and execute the instruction.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'delay_seconds' => [
                        'type'        => 'integer',
                        'description' => 'Delay in seconds before re-invocation.',
                    ],
                    'instruction' => [
                        'type'        => 'string',
                        'description' => 'What to do when woken up (e.g. "Turn off the living room light").',
                    ],
                ],
                'required' => ['delay_seconds', 'instruction'],
            ],
        ];
    }

    /**
     * Returns the three alfred_memory_* tool schemas.
     */
    private static function memoryTools(): array
    {
        return [
            [
                'name'        => 'alfred_memory_save',
                'description' => 'Save a persistent memory available in all future conversations.'
                               . ' Use scope "user" for personal info about the current user,'
                               . ' "global" for household facts shared across all users.'
                               . ' Choose a short, unique, descriptive label (e.g. "vacation-july-2026", "user-firstname").',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'scope'   => [
                            'type'        => 'string',
                            'enum'        => ['user', 'global'],
                            'description' => '"user" = personal to current user. "global" = shared with all household members.',
                        ],
                        'label'   => [
                            'type'        => 'string',
                            'description' => 'Short unique text identifier for this memory (e.g. "user-firstname", "vacation-july-2026").',
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'The fact or information to remember.',
                        ],
                    ],
                    'required' => ['scope', 'label', 'content'],
                ],
            ],
            [
                'name'        => 'alfred_memory_update',
                'description' => 'Update the content of an existing memory by its text label.'
                               . ' Labels are shown as headings in the memory block.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'label'   => ['type' => 'string', 'description' => 'Text label of the memory to update.'],
                        'content' => ['type' => 'string', 'description' => 'New content for this memory.'],
                    ],
                    'required' => ['label', 'content'],
                ],
            ],
            [
                'name'        => 'alfred_memory_forget',
                'description' => 'Permanently delete a memory by its text label.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'label' => ['type' => 'string', 'description' => 'Text label of the memory to delete.'],
                    ],
                    'required' => ['label'],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * Run one conversation turn.
     *
     * @param string $sessionId  Existing or new session UUID
     * @param string $userMessage The user's new message
     * @return string The final assistant text
     */
    public function run(string $sessionId, string $userMessage): string
    {
        // Ensure session exists and persist user message (skipped for continuation turns)
        if ($userMessage !== '') {
            $session = alfredConversation::getSession($sessionId);
            if ($session === null) {
                alfredConversation::createSession($sessionId, alfredConversation::autoTitle($userMessage));
            }
            alfredConversation::addMessage($sessionId, 'user', $userMessage);
        }

        // Load full history
        $messages = alfredConversation::getMessages($sessionId);

        // Build effective system prompt (base + persistent memory block)
        $effectiveSystemPrompt = $this->buildSystemPrompt();

        // Emit full system prompt for admin debugging
        if ($this->userProfil === 'admin') {
            $this->emit('debug', ['system_prompt' => $effectiveSystemPrompt]);
        }

        // Fetch available tools from JeedomMCP and inject synthetic tools
        $tools = [];
        try {
            $tools = $this->mcp->listTools();
        } catch (Exception $e) {
            // Non-fatal: agent works without tools (degraded mode)
            $this->emit('error', ['message' => 'JeedomMCP unavailable: ' . $e->getMessage()]);
        }
        // Prepend synthetic tools so they appear first in the list
        foreach (array_reverse(self::memoryTools()) as $t) {
            array_unshift($tools, $t);
        }
        array_unshift($tools, self::scheduleTool());

        // ReAct loop
        $finalText  = '';
        $iterations = 0;

        while ($iterations < $this->maxIterations) {
            $iterations++;

            $onDelta  = function (string $chunk): void {
                $this->emit('delta', ['text' => $chunk]);
            };
            $response = $this->llm->chatStream($messages, $tools, $effectiveSystemPrompt, $onDelta);

            if ($response['stop_reason'] === 'end_turn' || empty($response['tool_calls'])) {
                // Final text response — delta events were already emitted chunk by chunk
                $finalText = $response['text'];
                alfredConversation::saveAssistantResponse($sessionId, $response);
                break;
            }

            // Assistant wants to call tools — persist the assistant turn first
            alfredConversation::saveAssistantResponse($sessionId, $response);

            // Add to in-memory messages for next iteration
            $assistantMsg = [
                'role'       => 'assistant',
                'content'    => $response['text'],
                'tool_calls' => $response['tool_calls'],
            ];
            if (!empty($response['gemini_thought_parts'])) {
                $assistantMsg['gemini_thought_parts'] = $response['gemini_thought_parts'];
            }
            $messages[] = $assistantMsg;

            // Execute each tool call
            foreach ($response['tool_calls'] as $tc) {
                $this->emit('tool_call', ['name' => $tc['name'], 'input' => $tc['input']]);

                // Intercept synthetic tools before forwarding to MCP
                if ($tc['name'] === 'alfred_schedule') {
                    $result = $this->handleScheduleTool($sessionId, $tc['input']);
                } elseif (strpos($tc['name'], 'alfred_memory_') === 0) {
                    $result = $this->handleMemoryTool($tc['name'], $tc['input']);
                } else {
                    try {
                        $result = $this->mcp->callTool($tc['name'], $tc['input']);
                    } catch (Exception $e) {
                        $result = ['error' => $e->getMessage()];
                    }
                }

                $resultStr = is_array($result)
                    ? json_encode($result, JSON_UNESCAPED_UNICODE)
                    : (string)$result;

                $this->emit('tool_result', ['name' => $tc['name'], 'result' => $result]);

                alfredConversation::saveToolResult($sessionId, $tc['id'], $tc['name'], $result);

                $messages[] = [
                    'role'        => 'tool',
                    'tool_call_id' => $tc['id'],
                    'name'        => $tc['name'],
                    'content'     => $resultStr,
                ];
            }
        }

        if ($iterations >= $this->maxIterations && $finalText === '') {
            $this->emit('done', ['text' => '', 'iterations' => $iterations, 'limit_reached' => true]);
            return '';
        }

        $this->emit('done', ['text' => $finalText, 'iterations' => $iterations]);

        return $finalText;
    }

    // -------------------------------------------------------------------------
    // Synthetic tool handlers
    // -------------------------------------------------------------------------

    /**
     * Handle a call to the synthetic alfred_schedule tool.
     * Delegates to alfredScheduler and returns a confirmation string.
     */
    private function handleScheduleTool(string $sessionId, array $input): string
    {
        $delaySeconds = (int)($input['delay_seconds'] ?? 0);
        $instruction  = trim((string)($input['instruction'] ?? ''));

        if ($delaySeconds <= 0 || $instruction === '') {
            return 'Error: delay_seconds must be positive and instruction must not be empty.';
        }

        return alfredScheduler::schedule($sessionId, $delaySeconds, $instruction);
    }

    // -------------------------------------------------------------------------

    /**
     * Build the effective system prompt by appending the persistent memory block.
     */
    private function buildSystemPrompt(): string
    {
        $prompt = $this->systemPrompt;

        if ($this->userLogin === null) {
            return $prompt;
        }

        // Inject current date and user block
        $role    = $this->userProfil ?? 'user';
        $prompt .= "\n\n## Context"
                 . "\n- Current date and time: " . date('l, F j Y H:i')
                 . "\n- Current user login: " . $this->userLogin
                 . "\n- Current user role: " . $role;

        $memories = alfredMemory::loadForUser($this->userLogin);

        // Inject memory block
        if (!empty($memories)) {
            $block = "\n\n## Persistent memory\n";
            foreach ($memories as $m) {
                $heading = $m['label'] !== '' ? $m['label'] : (($m['scope'] === 'global' ? 'global' : 'personal') . '-' . $m['id']);
                $block  .= "\n### {$heading}\n{$m['content']}\n";
            }
            $prompt .= $block;
        }

        // Inject onboarding prompt when appropriate
        $userScope       = 'user:' . $this->userLogin;
        $hasUserMemory   = false;
        $hasGlobalMemory = false;
        foreach ($memories as $m) {
            if ($m['scope'] === $userScope)  $hasUserMemory   = true;
            if ($m['scope'] === 'global')    $hasGlobalMemory = true;
        }

        if (!$hasUserMemory) {
            $onboarding = (!$hasGlobalMemory)
                ? alfred::getFirstInstallPrompt()
                : alfred::getNewUserPrompt();

            if ($onboarding !== '') {
                $prompt .= "\n\n## First contact instructions\n" . $onboarding;
            }
        }

        return $prompt;
    }

    /**
     * Handle a call to one of the alfred_memory_* synthetic tools.
     */
    private function handleMemoryTool(string $name, array $input): string
    {
        $allowedScopes = alfredMemory::allowedScopes($this->userLogin);

        try {
            switch ($name) {
                case 'alfred_memory_save':
                    $rawScope = (string)($input['scope']   ?? '');
                    $label    = trim((string)($input['label']   ?? ''));
                    $content  = trim((string)($input['content'] ?? ''));
                    if ($label   === '') return 'Error: label must not be empty.';
                    if ($content === '') return 'Error: content must not be empty.';
                    if ($rawScope === 'user') {
                        if ($this->userLogin === null) {
                            return 'Error: cannot save a user-scoped memory without an authenticated user.';
                        }
                        $scope = 'user:' . $this->userLogin;
                    } else {
                        $scope = 'global';
                    }
                    alfredMemory::save($scope, $label, $content);
                    return "Memory '{$label}' saved.";

                case 'alfred_memory_update':
                    $label   = trim((string)($input['label']   ?? ''));
                    $content = trim((string)($input['content'] ?? ''));
                    if ($label   === '') return 'Error: label must not be empty.';
                    if ($content === '') return 'Error: content must not be empty.';
                    alfredMemory::updateByLabel($label, $content, $allowedScopes);
                    return "Memory '{$label}' updated.";

                case 'alfred_memory_forget':
                    $label = trim((string)($input['label'] ?? ''));
                    if ($label === '') return 'Error: label must not be empty.';
                    alfredMemory::forgetByLabel($label, $allowedScopes);
                    return "Memory '{$label}' deleted.";
            }
        } catch (Exception $e) {
            return 'Error: ' . $e->getMessage();
        }

        return 'Unknown memory tool.';
    }

    // -------------------------------------------------------------------------

    private function emit(string $type, array $data): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($type, $data);
        }
    }
}
