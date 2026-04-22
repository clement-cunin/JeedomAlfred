<?php

/**
 * Alfred ReAct agent loop.
 *
 * Orchestrates:
 *   - alfredLLM          : sends messages to the LLM, gets back text or tool calls
 *   - alfredMCPRegistry  : aggregates tools from all enabled MCP servers and routes calls
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
 */
class alfredAgent
{
    private alfredLLMAdapter   $llm;
    private alfredMCPRegistry  $registry;
    private int                $maxIterations;
    private string             $systemPrompt;
    /** @var callable|null */
    private $onEvent;

    public function __construct(
        ?alfredLLMAdapter  $llm      = null,
        ?alfredMCPRegistry $registry = null,
        ?callable          $onEvent  = null
    ) {
        $this->llm           = $llm      ?? alfredLLM::make();
        $this->maxIterations = alfred::getMaxIterations();
        $this->systemPrompt  = alfred::getSystemPrompt();
        $this->onEvent       = $onEvent;

        if ($registry !== null) {
            $this->registry = $registry;
        } else {
            $this->registry = alfredMCPRegistry::fromConfig(function (string $msg): void {
                $this->emit('error', ['message' => $msg]);
            });
        }
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

        // Fetch aggregated tool list from all enabled MCP servers
        $tools = $this->registry->listTools();

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
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $response['text'],
                'tool_calls' => $response['tool_calls'],
            ];

            // Execute each tool call
            foreach ($response['tool_calls'] as $tc) {
                $this->emit('tool_call', ['name' => $tc['name'], 'input' => $tc['input']]);

                try {
                    $result = $this->registry->callTool($tc['name'], $tc['input']);
                } catch (Exception $e) {
                    $result = ['error' => $e->getMessage()];
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

    private function emit(string $type, array $data): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($type, $data);
        }
    }
}
