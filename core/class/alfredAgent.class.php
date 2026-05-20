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
 *   file_create            — register base64 content as a downloadable session file
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
    /** @var string[] MCP errors collected during registry init, persisted at start of chat() */
    private array $pendingMcpErrors = [];

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
                $this->pendingMcpErrors[] = $msg;
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

    public static function getFileMeta(string $sessionId, string $fileId): ?array
    {
        $safeId   = preg_replace('/[^a-zA-Z0-9]/', '', $fileId);
        $metaPath = self::uploadDir($sessionId) . DIRECTORY_SEPARATOR . $safeId . '.json';
        if (!file_exists($metaPath)) return null;
        $meta = json_decode(file_get_contents($metaPath), true);
        return is_array($meta) ? $meta : null;
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

    /**
     * Register binary content as a session file. For use by external plugins.
     * Returns the new file_id.
     */
    public static function registerFile(
        string $sessionId,
        string $content,
        string $originalName,
        string $mimeType
    ): string {
        $dir = self::uploadDir($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new Exception("Cannot create upload directory for session {$sessionId}");
        }
        $fileId   = bin2hex(random_bytes(8));
        $ext      = self::extensionForMime($mimeType);
        $filename = $fileId . ($ext !== '' ? '.' . $ext : '');
        file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $content);
        $meta = [
            'file_id'       => $fileId,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => strlen($content),
            'filename'      => $filename,
        ];
        file_put_contents($dir . DIRECTORY_SEPARATOR . $fileId . '.json', json_encode($meta));

        $eventsFile = $dir . DIRECTORY_SEPARATOR . '_events.json';
        $pending    = file_exists($eventsFile) ? (json_decode(file_get_contents($eventsFile), true) ?: []) : [];
        $pending[]  = ['file_id' => $fileId, 'filename' => $originalName, 'mime_type' => $mimeType, 'size' => strlen($content)];
        file_put_contents($eventsFile, json_encode($pending), LOCK_EX);

        return $fileId;
    }

    /**
     * Register a file from an existing path. For use by external plugins.
     * Returns the new file_id.
     */
    public static function registerFileFromPath(
        string $sessionId,
        string $sourcePath,
        string $originalName,
        string $mimeType
    ): string {
        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new Exception("Cannot read source file: {$sourcePath}");
        }
        return self::registerFile($sessionId, $content, $originalName, $mimeType);
    }

    /**
     * Inject an async tool result into a session and run a continuation LLM turn.
     *
     * Intended for background processes that complete async tool work started during
     * a previous Alfred turn (e.g. a scanner, a long-running export, a remote job).
     *
     * The method:
     *   1. Injects a synthetic assistant message with a tool_calls entry
     *   2. Injects the tool result message
     *   3. Runs alfredAgent in continuation mode (empty user message) so the LLM
     *      sees the tool result and generates a natural response or chains further actions
     *
     * Minimal bootstrap:
     *   require_once '/var/www/html/core/php/core.inc.php';
     *   require_once '/var/www/html/plugins/alfred/core/class/alfredAgent.class.php';
     *   alfredAgent::resumeWithAsyncToolResult($sessionId, 'ext_myplugin_mytool', $result);
     *
     * @api Stable public contract — callable from third-party plugins.
     *
     * @param string  $sessionId   The Alfred session to resume
     * @param string  $toolName    Full tool name as registered in Alfred (e.g. "ext_jaganin_scanner_scan")
     * @param array   $toolResult  The tool result payload to inject
     * @param ?string $userLogin   Resolved from session if null (via alfredConversation::getUserLogin)
     * @param ?string $userProfil  Resolved from user table if null
     * @return string              The assistant's final text response
     */
    public static function resumeWithAsyncToolResult(
        string  $sessionId,
        string  $toolName,
        array   $toolResult,
        ?string $userLogin  = null,
        ?string $userProfil = null
    ): string {
        if ($userLogin === null) {
            $userLogin = alfredConversation::getUserLogin($sessionId);
        }
        if ($userProfil === null && $userLogin !== null) {
            $userProfil = 'user';
            try {
                $u = user::byLogin($userLogin);
                if ($u && $u->getProfils() === 'admin') {
                    $userProfil = 'admin';
                }
            } catch (Exception $ignored) {}
        }

        $toolCallId = bin2hex(random_bytes(8));

        alfredConversation::saveAssistantResponse($sessionId, [
            'text'        => '',
            'tool_calls'  => [['id' => $toolCallId, 'name' => $toolName, 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage'       => [],
        ]);

        alfredConversation::saveToolResult($sessionId, $toolCallId, $toolName, $toolResult);

        return (new alfredAgent(null, null, null, $userLogin, $userProfil))->run($sessionId, '');
    }

    /**
     * Inject an async tool error into a session and run a continuation LLM turn.
     *
     * Symmetric to resumeWithAsyncToolResult() — use when a background tool failed
     * so the LLM can inform the user naturally.
     *
     * @api Stable public contract — callable from third-party plugins.
     *
     * @param string  $sessionId    The Alfred session to resume
     * @param string  $toolName     Full tool name as registered in Alfred
     * @param string  $errorMessage Human-readable error description
     * @param ?string $userLogin    Resolved from session if null
     * @param ?string $userProfil   Resolved from user table if null
     * @return string               The assistant's final text response
     */
    public static function resumeWithAsyncToolError(
        string  $sessionId,
        string  $toolName,
        string  $errorMessage,
        ?string $userLogin  = null,
        ?string $userProfil = null
    ): string {
        if ($userLogin === null) {
            $userLogin = alfredConversation::getUserLogin($sessionId);
        }
        if ($userProfil === null && $userLogin !== null) {
            $userProfil = 'user';
            try {
                $u = user::byLogin($userLogin);
                if ($u && $u->getProfils() === 'admin') {
                    $userProfil = 'admin';
                }
            } catch (Exception $ignored) {}
        }

        $toolCallId = bin2hex(random_bytes(8));

        alfredConversation::saveAssistantResponse($sessionId, [
            'text'        => '',
            'tool_calls'  => [['id' => $toolCallId, 'name' => $toolName, 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage'       => [],
        ]);

        alfredConversation::saveToolResult($sessionId, $toolCallId, $toolName, [
            'error'   => true,
            'message' => $errorMessage,
        ]);

        return (new alfredAgent(null, null, null, $userLogin, $userProfil))->run($sessionId, '');
    }

    private static function extensionForMime(string $mimeType): string
    {
        $map = [
            'application/pdf'  => 'pdf',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'text/plain'       => 'txt',
            'text/html'        => 'html',
            'application/json' => 'json',
        ];
        return $map[$mimeType] ?? '';
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

    private static function fileCreateTool(): array
    {
        return [
            'name'        => 'file_create',
            'description' => 'Save content as a downloadable file in the current session.'
                           . ' Returns a file_id. The file will appear in the "Attached files" list'
                           . ' from the next turn onward and can be downloaded by the user.'
                           . ' Use this when you have produced or retrieved binary content'
                           . ' (e.g. a PDF from Paperless) that the user should be able to download.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'content_base64' => [
                        'type'        => 'string',
                        'description' => 'Base64-encoded file content.',
                    ],
                    'filename' => [
                        'type'        => 'string',
                        'description' => 'Desired filename with extension (e.g. "invoice.pdf").',
                    ],
                    'mime_type' => [
                        'type'        => 'string',
                        'description' => 'MIME type (e.g. "application/pdf"). Guessed from filename extension if omitted.',
                    ],
                ],
                'required' => ['content_base64', 'filename'],
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

        // Persist any MCP init errors so they survive page reloads (excluded from LLM context)
        foreach ($this->pendingMcpErrors as $errMsg) {
            alfredConversation::addMessage($sessionId, 'error', $errMsg, ['error' => true]);
        }
        $this->pendingMcpErrors = [];

        // Load full history
        $messages = alfredConversation::getMessages($sessionId);
        $timing['session_load'] = (int)round((microtime(true) - $tPhase) * 1000);

        // Flush files registered by background processes (e.g. async scanner) before building the prompt
        $this->flushPendingFileEvents($sessionId);

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
        array_unshift($tools, self::fileCreateTool());
        if (!empty($uploadedFiles)) {
            array_unshift($tools, self::fileReadTool());
        }

        // ReAct loop
        $finalText    = '';
        $iterations   = 0;
        $systemChars  = strlen($effectiveSystemPrompt);
        $toolsChars   = strlen(json_encode($tools));
        $prevMsgChars = strlen(json_encode($messages));
        $llmProvider  = $this->llm->getProvider();
        $llmModel     = $this->llm->getModel();

        while ($iterations < $this->maxIterations) {
            $iterations++;

            $currentMsgChars = strlen(json_encode($messages));
            $newResChars     = max(0, $currentMsgChars - $prevMsgChars);

            $onDelta  = function (string $chunk): void {
                $this->emit('delta', ['text' => $chunk]);
            };
            $tLlm       = microtime(true);
            $response   = $this->llm->chatStream($messages, $tools, $effectiveSystemPrompt, $onDelta);
            $durationMs = (int)round((microtime(true) - $tLlm) * 1000);
            $timing['llm_calls'][] = [
                'iteration'   => $iterations,
                'duration_ms' => $durationMs,
            ];

            $llmInfo = ['provider' => $llmProvider, 'model' => $llmModel];

            if ($response['stop_reason'] === 'end_turn' || empty($response['tool_calls'])) {
                // Final text response — delta events were already emitted chunk by chunk
                $finalText = $response['text'];
                $tDb = microtime(true);
                $msgId = alfredConversation::saveAssistantResponse($sessionId, $response, $llmInfo);
                $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);
                alfredConversation::saveLlmCall($sessionId, $msgId, [
                    'iteration'     => $iterations,
                    'provider'      => $llmProvider,
                    'model'         => $llmModel,
                    'input_tokens'  => $response['usage']['input_tokens']  ?? 0,
                    'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                    'duration_ms'   => $durationMs,
                    'system_chars'  => $systemChars,
                    'history_chars' => $currentMsgChars,
                    'tools_chars'   => $toolsChars,
                    'new_res_chars' => $newResChars,
                ]);
                break;
            }

            // Assistant wants to call tools — persist the assistant turn first
            $tDb = microtime(true);
            $msgId = alfredConversation::saveAssistantResponse($sessionId, $response, $llmInfo);
            $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);
            alfredConversation::saveLlmCall($sessionId, $msgId, [
                'iteration'     => $iterations,
                'provider'      => $llmProvider,
                'model'         => $llmModel,
                'input_tokens'  => $response['usage']['input_tokens']  ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                'duration_ms'   => $durationMs,
                'system_chars'  => $systemChars,
                'history_chars' => $currentMsgChars,
                'tools_chars'   => $toolsChars,
                'new_res_chars' => $newResChars,
            ]);
            $prevMsgChars = $currentMsgChars;

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
                } elseif ($tc['name'] === 'file_create') {
                    $result = $this->handleFileCreateTool($sessionId, $tc['input']);
                } elseif (strpos($tc['name'], 'alfred_memory_') === 0) {
                    $result = $this->handleMemoryTool($tc['name'], $tc['input']);
                } else {
                    try {
                        $result = $this->registry->callTool($tc['name'], $tc['input'], $sessionId);
                    } catch (Exception $e) {
                        $result = ['error' => $e->getMessage()];
                    }
                }
                $timing['tool_calls'][] = [
                    'tool'        => $tc['name'],
                    'duration_ms' => (int)round((microtime(true) - $tTool) * 1000),
                ];

                // Strip internal async task marker before it reaches the LLM or the DB
                $asyncTaskId = null;
                if (is_array($result) && isset($result['_async_task_id'])) {
                    $asyncTaskId = (int) $result['_async_task_id'];
                    unset($result['_async_task_id']);
                }

                $resultStr = is_array($result)
                    ? json_encode($result, JSON_UNESCAPED_UNICODE)
                    : (string)$result;

                $this->flushPendingFileEvents($sessionId);
                $this->emit('tool_result', ['name' => $tc['name'], 'result' => $result]);

                $tDb = microtime(true);
                alfredConversation::saveToolResult($sessionId, $tc['id'], $tc['name'], $result);
                $timing['db_writes'] += (int)round((microtime(true) - $tDb) * 1000);

                // Create the pending UI message AFTER the tool result so it appears last in the conversation
                if ($asyncTaskId !== null) {
                    alfredAsyncTask::linkMessage($asyncTaskId, $sessionId);
                }

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
     */
    private function handleScheduleTool(string $sessionId, array $input): array
    {
        $delaySeconds = (int)($input['delay_seconds'] ?? 0);
        $instruction  = trim((string)($input['instruction'] ?? ''));

        if ($delaySeconds <= 0 || $instruction === '') {
            return ['error' => 'delay_seconds must be positive and instruction must not be empty.'];
        }

        return alfredAsyncTask::schedule($sessionId, $delaySeconds, $instruction);
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
     * Handle a call to the file_create synthetic tool.
     */
    private function handleFileCreateTool(string $sessionId, array $input): array
    {
        $b64      = trim((string)($input['content_base64'] ?? ''));
        $filename = trim((string)($input['filename'] ?? ''));
        if ($b64 === '' || $filename === '') {
            return ['error' => 'content_base64 and filename are required'];
        }
        $content = base64_decode($b64, true);
        if ($content === false) {
            return ['error' => 'Invalid base64 content'];
        }
        $mimeType = trim((string)($input['mime_type'] ?? ''));
        if ($mimeType === '') {
            $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeMap  = [
                'pdf'  => 'application/pdf',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'txt'  => 'text/plain',
                'html' => 'text/html',
                'json' => 'application/json',
            ];
            $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        }
        try {
            $fileId = self::registerFile($sessionId, $content, $filename, $mimeType);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
        return [
            'file_id'   => $fileId,
            'filename'  => $filename,
            'mime_type' => $mimeType,
            'size'      => strlen($content),
        ];
    }

    /**
     * Flush any file_added events queued by registerFile() (possibly from an external plugin process).
     * Must be called from within the active agent turn so events reach the SSE stream.
     */
    private function flushPendingFileEvents(string $sessionId): void
    {
        $eventsFile = self::uploadDir($sessionId) . DIRECTORY_SEPARATOR . '_events.json';
        if (!file_exists($eventsFile)) return;
        $pending = json_decode(file_get_contents($eventsFile), true) ?: [];
        unlink($eventsFile);
        foreach ($pending as $ev) {
            $this->emit('file_added', $ev);
        }
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

        // Inject tool error reporting instructions
        $prompt .= "\n\n## Tool error reporting"
                 . "\nWhen a tool returns an error, always report the exact error message to the user verbatim,"
                 . " formatted as: \"L'outil `<tool_name>` a retourné une erreur : `<exact_error_message>`\"."
                 . " Do not paraphrase, interpret, or summarize tool errors — always quote them exactly.";

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
