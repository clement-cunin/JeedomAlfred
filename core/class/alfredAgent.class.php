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
 *   timing     — per-stage latency breakdown (admin only)
 *                {session_load, prompt_build, llm_calls, tool_calls, db_writes, total}
 *
 * Synthetic tools (handled locally, never forwarded to MCP):
 *   alfred_schedule        — schedule a deferred re-invocation of the agent
 *   uploaded_file_read     — read a session-scoped uploaded file as base64
 */
class alfredAgent
{
    private alfredLLMAdapter  $llm;
    private alfredMCPRegistry $registry;
    private int               $maxIterations;
    private string            $systemPrompt;
    private ?string           $userLogin;
    private ?string           $userProfil;
    /** @var callable|null */
    private $onEvent;

    public function __construct(
        ?alfredLLMAdapter  $llm             = null,
        ?alfredMCPRegistry $registry        = null,
        ?callable          $onEvent         = null,
        ?string            $userLogin       = null,
        ?string            $userProfil      = null,
        int                $extraIterations = 0
    ) {
        $this->llm           = $llm ?? alfredLLM::make();
        $this->maxIterations = alfred::getMaxIterations() + $extraIterations;
        $this->systemPrompt  = alfred::getSystemPrompt();
        $this->onEvent       = $onEvent;
        $this->userLogin     = $userLogin;
        $this->userProfil    = $userProfil;

        if ($registry !== null) {
            $this->registry = $registry;
        } else {
            $this->registry = alfredMCPRegistry::fromConfig(function (string $msg): void {
                $this->emit('error', ['message' => $msg]);
            });
        }
    }

    // -------------------------------------------------------------------------
    // Synthetic tool definitions
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Upload helpers
    // -------------------------------------------------------------------------

    private static function uploadDir(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alfred' . DIRECTORY_SEPARATOR . $safe;
    }

    public static function listUploadedFiles(string $sessionId): array
    {
        $dir   = self::uploadDir($sessionId);
        $files = [];
        if (!is_dir($dir)) return $files;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') as $metaFile) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (is_array($meta) && isset($meta['file_id'])) {
                $files[] = $meta;
            }
        }
        return $files;
    }

    public static function getFilePath(string $sessionId, string $fileId): ?string
    {
        $safeId   = preg_replace('/[^a-zA-Z0-9]/', '', $fileId);
        $dir      = self::uploadDir($sessionId);
        $metaPath = $dir . DIRECTORY_SEPARATOR . $safeId . '.json';
        if (!file_exists($metaPath)) return null;
        $meta     = json_decode(file_get_contents($metaPath), true);
        if (!is_array($meta) || !isset($meta['filename'])) return null;
        $path = $dir . DIRECTORY_SEPARATOR . $meta['filename'];
        return file_exists($path) ? $path : null;
    }

    public static function cleanupSessionFiles(string $sessionId): void
    {
        $dir = self::uploadDir($sessionId);
        if (!is_dir($dir)) return;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) {
            if (is_file($f)) unlink($f);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Synthetic tool definitions
    // -------------------------------------------------------------------------

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

    private static function fileReadTool(): array
    {
        return [
            'name'        => 'uploaded_file_read',
            'description' => 'Read the content of a file attached to this conversation.'
                           . ' Returns the file as base64-encoded data along with its MIME type.'
                           . ' Use this when you need to analyse the content of an attached file.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'file_id' => [
                        'type'        => 'string',
                        'description' => 'The file_id of the uploaded file, as listed in the system context.',
                    ],
                ],
                'required' => ['file_id'],
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
        $tStart = microtime(true);
        $timing = [
            'session_load' => 0,
            'prompt_build' => 0,
            'llm_calls'    => [],
            'tool_calls'   => [],
            'db_writes'    => 0,
            'total'        => 0,
        ];

        // Ensure session exists and persist user message (skipped for continuation turns)
        $tPhase = microtime(true);
        if ($userMessage !== '') {
            $session = alfredConversation::getSession($sessionId);
            if ($session === null) {
                alfredConversation::createSession($sessionId, alfredConversation::autoTitle($userMessage), $this->userLogin);
            } elseif ($this->userProfil !== 'admin' && !alfredConversation::sessionBelongsTo($sessionId, $this->userLogin)) {
                throw new Exception('Access denied');
            }
            alfredConversation::addMessage($sessionId, 'user', $userMessage);
        }

        // Load full history
        $messages = alfredConversation::getMessages($sessionId);
        $timing['session_load'] = (int)round((microtime(true) - $tPhase) * 1000);

        // Build effective system prompt (base + persistent memory block + attached files)
        $tPhase = microtime(true);
        $effectiveSystemPrompt = $this->buildSystemPrompt();

        $uploadedFiles = self::listUploadedFiles($sessionId);
        if (!empty($uploadedFiles)) {
            $fileBlock = "\n\n## Attached files\n"
                       . "The user has attached the following files to this conversation."
                       . " Use `uploaded_file_read` to read their content when needed.\n";
            foreach ($uploadedFiles as $f) {
                $sizeKb    = round($f['size'] / 1024, 1);
                $fileBlock .= "\n- **{$f['original_name']}** ({$f['mime_type']}, {$sizeKb} KB)"
                            . " — file_id: `{$f['file_id']}`";
            }
            $effectiveSystemPrompt .= $fileBlock;
        }

        $timing['prompt_build'] = (int)round((microtime(true) - $tPhase) * 1000);

        // Emit full system prompt for admin debugging
        if ($this->userProfil === 'admin') {
            $this->emit('debug', ['system_prompt' => $effectiveSystemPrompt]);
        }

        // Fetch aggregated tool list from all enabled MCP servers, then prepend synthetic tools
        $tools = $this->registry->listTools();
        foreach (array_reverse(self::memoryTools()) as $t) {
            array_unshift($tools, $t);
        }
        array_unshift($tools, self::scheduleTool());
        if (!empty($uploadedFiles)) {
            array_unshift($tools, self::fileReadTool());
        }

        // ReAct loop
        $finalText  = '';
        $iterations = 0;

        while ($iterations < $this->maxIterations) {
            $iterations++;

            $onDelta  = function (string $chunk): void {
                $this->emit('delta', ['text' => $chunk]);
            };
            $tLlm     = microtime(true);
            $response = $this->llm->chatStream($messages, $tools, $effectiveSystemPrompt, $onDelta);
            $timing['llm_calls'][] = [
                'iteration'   => $iterations,
                'duration_ms' => (int)round((microtime(true) - $tLlm) * 1000),
            ];

            if ($response['stop_reason'] === 'end_turn' || empty($response['tool_calls'])) {
                // Final text response — delta events were already emitted chunk by chunk
                $finalText = $response['text'];
                $tDb = microtime(true);
                alfredConversation::saveAssistantResponse($sessionId, $response);
                $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);
                break;
            }

            // Assistant wants to call tools — persist the assistant turn first
            $tDb = microtime(true);
            alfredConversation::saveAssistantResponse($sessionId, $response);
            $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);

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

                // Intercept synthetic tools before forwarding to registry
                $tTool = microtime(true);
                if ($tc['name'] === 'alfred_schedule') {
                    $result = $this->handleScheduleTool($sessionId, $tc['input']);
                } elseif ($tc['name'] === 'uploaded_file_read') {
                    $result = $this->handleFileReadTool($sessionId, $tc['input']);
                } elseif (strpos($tc['name'], 'alfred_memory_') === 0) {
                    $result = $this->handleMemoryTool($tc['name'], $tc['input']);
                } else {
                    try {
                        $result = $this->registry->callTool($tc['name'], $tc['input']);
                    } catch (Exception $e) {
                        $result = ['error' => $e->getMessage()];
                    }
                }
                $timing['tool_calls'][] = [
                    'tool'        => $tc['name'],
                    'duration_ms' => (int)round((microtime(true) - $tTool) * 1000),
                ];

                $resultStr = is_array($result)
                    ? json_encode($result, JSON_UNESCAPED_UNICODE)
                    : (string)$result;

                $this->emit('tool_result', ['name' => $tc['name'], 'result' => $result]);

                $tDb = microtime(true);
                alfredConversation::saveToolResult($sessionId, $tc['id'], $tc['name'], $result);
                $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);

                $messages[] = [
                    'role'        => 'tool',
                    'tool_call_id' => $tc['id'],
                    'name'        => $tc['name'],
                    'content'     => $resultStr,
                ];
            }
        }

        $timing['total'] = (int)round((microtime(true) - $tStart) * 1000);

        if ($iterations >= $this->maxIterations && $finalText === '') {
            if ($this->userProfil === 'admin') {
                $this->emit('timing', $timing);
            }
            $this->emit('done', ['text' => '', 'iterations' => $iterations, 'limit_reached' => true]);
            return '';
        }

        if ($this->userProfil === 'admin') {
            $this->emit('timing', $timing);
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
    private function handleScheduleTool(string $sessionId, array $input): array
    {
        $delaySeconds = (int)($input['delay_seconds'] ?? 0);
        $instruction  = trim((string)($input['instruction'] ?? ''));

        if ($delaySeconds <= 0 || $instruction === '') {
            return ['error' => 'delay_seconds must be positive and instruction must not be empty.'];
        }

        return alfredScheduler::schedule($sessionId, $delaySeconds, $instruction);
    }

    // -------------------------------------------------------------------------

    /**
     * Handle a call to the uploaded_file_read synthetic tool.
     */
    private function handleFileReadTool(string $sessionId, array $input): array
    {
        $fileId = trim((string)($input['file_id'] ?? ''));
        if ($fileId === '') {
            return ['error' => 'file_id is required'];
        }

        $safeId   = preg_replace('/[^a-zA-Z0-9]/', '', $fileId);
        $dir      = self::uploadDir($sessionId);
        $metaPath = $dir . DIRECTORY_SEPARATOR . $safeId . '.json';

        if (!file_exists($metaPath)) {
            return ['error' => 'File not found'];
        }

        $meta     = json_decode(file_get_contents($metaPath), true);
        $filePath = $dir . DIRECTORY_SEPARATOR . $meta['filename'];

        if (!file_exists($filePath)) {
            return ['error' => 'File data missing'];
        }

        return [
            'file_id'   => $meta['file_id'],
            'filename'  => $meta['original_name'],
            'mime_type' => $meta['mime_type'],
            'size'      => $meta['size'],
            'data'      => base64_encode(file_get_contents($filePath)),
        ];
    }

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
