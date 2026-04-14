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
    /** @var callable|null */
    private $onEvent;

    public function __construct(
        ?alfredLLMAdapter $llm   = null,
        ?alfredMCP        $mcp   = null,
        ?callable         $onEvent = null
    ) {
        $this->llm           = $llm   ?? alfredLLM::make();
        $this->mcp           = $mcp   ?? new alfredMCP();
        $this->maxIterations = alfred::getMaxIterations();
        $this->systemPrompt  = alfred::getSystemPrompt();
        $this->onEvent       = $onEvent;
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
        // Ensure session exists
        $session = alfredConversation::getSession($sessionId);
        if ($session === null) {
            alfredConversation::createSession($sessionId, alfredConversation::autoTitle($userMessage));
        }

        // Persist user message
        alfredConversation::addMessage($sessionId, 'user', $userMessage);

        // Load full history
        $messages = alfredConversation::getMessages($sessionId);

        // Fetch available tools from JeedomMCP and inject synthetic tools
        $tools = [];
        try {
            $tools = $this->mcp->listTools();
        } catch (Exception $e) {
            // Non-fatal: agent works without tools (degraded mode)
            $this->emit('error', ['message' => 'JeedomMCP unavailable: ' . $e->getMessage()]);
        }
        // Prepend synthetic tools so they appear first in the list
        array_unshift($tools, self::scheduleTool());

        // ReAct loop
        $finalText  = '';
        $iterations = 0;

        while ($iterations < $this->maxIterations) {
            $iterations++;

            $onDelta  = function (string $chunk): void {
                $this->emit('delta', ['text' => $chunk]);
            };
            $response = $this->llm->chatStream($messages, $tools, $this->systemPrompt, $onDelta);

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
            $finalText = 'Maximum iterations reached without a final answer.';
            alfredConversation::addMessage($sessionId, 'assistant', $finalText);
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

    private function emit(string $type, array $data): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($type, $data);
        }
    }
}
