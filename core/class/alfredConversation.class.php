<?php

/**
 * Alfred conversation & message persistence.
 *
 * Conversations: session_id, title, created_at, updated_at
 * Messages:      session_id, role (user|assistant|tool), content (JSON or string), metadata (JSON)
 */
class alfredConversation
{
    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    public static function createSession(string $sessionId, string $title = '', ?string $userLogin = null): void
    {
        DB::Prepare(
            'INSERT INTO alfred_conversation (session_id, title, user_login) VALUES (:session_id, :title, :user_login)',
            [':session_id' => $sessionId, ':title' => $title, ':user_login' => $userLogin],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function getUserLogin(string $sessionId): ?string
    {
        $row = self::getSession($sessionId);
        return ($row && isset($row['user_login'])) ? ($row['user_login'] ?: null) : null;
    }

    public static function getSession(string $sessionId): ?array
    {
        $row = DB::Prepare(
            'SELECT * FROM alfred_conversation WHERE session_id = :session_id LIMIT 1',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    public static function listSessions(int $limit = 50, ?string $userLogin = null): array
    {
        $n = max(1, (int)$limit);
        if ($userLogin !== null) {
            return DB::Prepare(
                "SELECT * FROM alfred_conversation WHERE user_login = :user_login ORDER BY updated_at DESC LIMIT {$n}",
                [':user_login' => $userLogin],
                DB::FETCH_TYPE_ALL
            ) ?: [];
        }
        return DB::Prepare(
            "SELECT * FROM alfred_conversation ORDER BY updated_at DESC LIMIT {$n}",
            [],
            DB::FETCH_TYPE_ALL
        ) ?: [];
    }

    public static function sessionBelongsTo(string $sessionId, ?string $userLogin): bool
    {
        $session = self::getSession($sessionId);
        if ($session === null) {
            return false;
        }
        return $session['user_login'] === $userLogin;
    }

    public static function updateSessionTitle(string $sessionId, string $title): void
    {
        DB::Prepare(
            'UPDATE alfred_conversation SET title = :title WHERE session_id = :session_id',
            [':title' => $title, ':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function touchSession(string $sessionId): void
    {
        DB::Prepare(
            'UPDATE alfred_conversation SET updated_at = NOW() WHERE session_id = :session_id',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Returns the list of MCP server keys explicitly activated by the LLM for this session.
     * This is the source of truth for which servers' tools are loaded on each turn —
     * it is never inferred from the message history.
     */
    public static function getActiveMcpServers(string $sessionId): array
    {
        $session = self::getSession($sessionId);
        if ($session === null || empty($session['active_mcp_servers'])) {
            return [];
        }
        $decoded = json_decode($session['active_mcp_servers'], true);
        return is_array($decoded) ? array_values(array_unique(array_map('strval', $decoded))) : [];
    }

    /**
     * Mark an MCP server as activated for this session (idempotent). Persisted immediately
     * so the activation survives a page reload without needing to re-scan message history.
     */
    public static function activateMcpServer(string $sessionId, string $server): void
    {
        $current = self::getActiveMcpServers($sessionId);
        if (in_array($server, $current, true)) {
            return;
        }
        $current[] = $server;
        DB::Prepare(
            'UPDATE alfred_conversation SET active_mcp_servers = :active_mcp_servers WHERE session_id = :session_id',
            [':active_mcp_servers' => json_encode($current, JSON_UNESCAPED_UNICODE), ':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function deleteSession(string $sessionId): void
    {
        DB::Prepare(
            'DELETE FROM alfred_message WHERE session_id = :session_id',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
        DB::Prepare(
            'DELETE FROM alfred_conversation WHERE session_id = :session_id',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
    }

    // -------------------------------------------------------------------------
    // Messages
    // -------------------------------------------------------------------------

    /**
     * Append one message to a session.
     *
     * @param string $role     user | assistant | tool
     * @param mixed  $content  String or array (will be JSON-encoded)
     * @param array  $metadata Optional extra data (tool_call_id, tool_calls, name…)
     */
    public static function addMessage(string $sessionId, string $role, $content, array $metadata = []): int
    {
        $contentStr  = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : (string)$content;
        $metadataStr = empty($metadata)   ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE);

        DB::Prepare(
            'INSERT INTO alfred_message (session_id, role, content, metadata)
             VALUES (:session_id, :role, :content, :metadata)',
            [
                ':session_id' => $sessionId,
                ':role'       => $role,
                ':content'    => $contentStr,
                ':metadata'   => $metadataStr,
            ],
            DB::FETCH_TYPE_ROW
        );

        $row = DB::Prepare('SELECT LAST_INSERT_ID() AS id', [], DB::FETCH_TYPE_ROW);
        $id  = isset($row['id']) ? (int)$row['id'] : 0;

        self::touchSession($sessionId);
        return $id;
    }

    /**
     * Create a pending UI message linked to an async task. Returns the new message ID.
     */
    public static function createPendingMessage(string $sessionId, string $displayText, int $taskId): int
    {
        return self::addMessage($sessionId, 'pending', $displayText, [
            'task_id'      => $taskId,
            'async_status' => 'pending',
        ]);
    }

    /**
     * Update the async_status (and optional result/error_msg) of a pending message.
     */
    public static function updatePendingMessage(int $messageId, string $status, array $data = []): void
    {
        $row = DB::Prepare(
            'SELECT metadata FROM alfred_message WHERE id = :id LIMIT 1',
            [':id' => $messageId],
            DB::FETCH_TYPE_ROW
        );
        if (!$row) {
            return;
        }
        $meta                 = $row['metadata'] ? json_decode($row['metadata'], true) : [];
        $meta['async_status'] = $status;
        if (array_key_exists('result', $data))    $meta['result']    = $data['result'];
        if (array_key_exists('error_msg', $data)) $meta['error_msg'] = $data['error_msg'];

        DB::Prepare(
            'UPDATE alfred_message SET metadata = :metadata WHERE id = :id',
            [':metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE), ':id' => $messageId],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Load messages for a session as internal Alfred format (for LLM context).
     * Error and pending messages are excluded — they are display-only.
     */
    public static function getMessages(string $sessionId): array
    {
        $rows = DB::Prepare(
            'SELECT role, content, metadata FROM alfred_message
             WHERE session_id = :session_id ORDER BY id ASC',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $meta    = $row['metadata'] ? json_decode($row['metadata'], true) : [];
            $content = $row['content'];

            // Skip display-only messages
            if (!empty($meta['error'])) continue;
            if ($row['role'] === 'pending') continue;

            $msg = ['role' => $row['role'], 'content' => $content];

            // Restore tool_calls on assistant messages
            if ($row['role'] === 'assistant') {
                if (!empty($meta['tool_calls'])) {
                    $msg['tool_calls'] = $meta['tool_calls'];
                }
                if (!empty($meta['gemini_thought_parts'])) {
                    $msg['gemini_thought_parts'] = $meta['gemini_thought_parts'];
                }
            }
            // Restore tool result fields
            if ($row['role'] === 'tool') {
                $msg['tool_call_id'] = $meta['tool_call_id'] ?? '';
                $msg['name']         = $meta['name'] ?? '';
            }

            $out[] = $msg;
        }
        return $out;
    }

    /**
     * Load messages for display in the UI (includes error messages with error=true flag).
     */
    public static function getDisplayMessages(string $sessionId): array
    {
        $rows = DB::Prepare(
            'SELECT role, content, metadata FROM alfred_message
             WHERE session_id = :session_id ORDER BY id ASC',
            [':session_id' => $sessionId],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $meta    = $row['metadata'] ? json_decode($row['metadata'], true) : [];
            $content = $row['content'];

            $msg = ['role' => $row['role'], 'content' => $content];

            if ($row['role'] === 'assistant') {
                if (!empty($meta['tool_calls'])) {
                    $msg['tool_calls'] = $meta['tool_calls'];
                }
                if (!empty($meta['error'])) {
                    $msg['error'] = true;
                }
                if (!empty($meta['provider'])) {
                    $msg['provider'] = $meta['provider'];
                    $msg['model']    = $meta['model'] ?? '';
                }
            }
            if ($row['role'] === 'user' && ($meta['type'] ?? '') === 'scenario') {
                $msg['scenario_display'] = $meta['display'] ?? $content;
            }
            if ($row['role'] === 'tool') {
                $msg['tool_call_id'] = $meta['tool_call_id'] ?? '';
                $msg['name']         = $meta['name'] ?? '';
            }
            if ($row['role'] === 'pending') {
                $msg['display_text'] = $content;
                $msg['async_status'] = $meta['async_status'] ?? 'pending';
                $msg['task_id']      = $meta['task_id'] ?? null;
                if (isset($meta['result']))    $msg['result']    = $meta['result'];
                if (isset($meta['error_msg'])) $msg['error_msg'] = $meta['error_msg'];
            }

            $out[] = $msg;
        }
        return $out;
    }

    /**
     * Persist a full normalized LLM response into the session.
     *
     * $response  = ['text' => '...', 'tool_calls' => [...], 'stop_reason' => '...', 'usage' => [...]]
     * $llmInfo   = ['provider' => '...', 'model' => '...']  (optional, stored in metadata)
     * Returns the new message id.
     */
    public static function saveAssistantResponse(string $sessionId, array $response, array $llmInfo = []): int
    {
        $meta = [];
        if (!empty($response['tool_calls'])) {
            $meta['tool_calls'] = $response['tool_calls'];
        }
        if (!empty($response['gemini_thought_parts'])) {
            $meta['gemini_thought_parts'] = $response['gemini_thought_parts'];
        }
        if (!empty($llmInfo['provider'])) {
            $meta['provider'] = $llmInfo['provider'];
        }
        if (!empty($llmInfo['model'])) {
            $meta['model'] = $llmInfo['model'];
        }
        return self::addMessage($sessionId, 'assistant', $response['text'], $meta);
    }

    /**
     * Persist one LLM API call's metrics into alfred_llm_call.
     *
     * $data keys: iteration, provider, model, input_tokens, output_tokens,
     *             duration_ms, system_chars, history_chars, tools_chars, new_res_chars
     */
    public static function saveLlmCall(string $sessionId, ?int $messageId, array $data): void
    {
        DB::Prepare(
            'INSERT INTO alfred_llm_call
             (session_id, message_id, iteration, provider, model,
              input_tokens, output_tokens, duration_ms,
              system_chars, history_chars, tools_chars, new_res_chars)
             VALUES
             (:session_id, :message_id, :iteration, :provider, :model,
              :input_tokens, :output_tokens, :duration_ms,
              :system_chars, :history_chars, :tools_chars, :new_res_chars)',
            [
                ':session_id'    => $sessionId,
                ':message_id'    => $messageId ?: null,
                ':iteration'     => (int)($data['iteration']     ?? 1),
                ':provider'      => (string)($data['provider']   ?? ''),
                ':model'         => (string)($data['model']      ?? ''),
                ':input_tokens'  => (int)($data['input_tokens']  ?? 0),
                ':output_tokens' => (int)($data['output_tokens'] ?? 0),
                ':duration_ms'   => (int)($data['duration_ms']   ?? 0),
                ':system_chars'  => (int)($data['system_chars']  ?? 0),
                ':history_chars' => (int)($data['history_chars'] ?? 0),
                ':tools_chars'   => (int)($data['tools_chars']   ?? 0),
                ':new_res_chars' => (int)($data['new_res_chars'] ?? 0),
            ],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Persist a tool result into the session. If the result carries an error,
     * it is additionally logged to `alfred_tool_error` (with the tool's
     * arguments) for later analysis and debugging.
     */
    public static function saveToolResult(string $sessionId, string $toolCallId, string $toolName, $result, array $arguments = []): void
    {
        $content = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string)$result;
        self::addMessage($sessionId, 'tool', $content, [
            'tool_call_id' => $toolCallId,
            'name'         => $toolName,
        ]);

        $errorMessage = self::extractToolError($result);
        if ($errorMessage !== null) {
            self::logToolError($sessionId, $toolName, $arguments, $errorMessage);
        }
    }

    /**
     * Extract a human-readable error message from a tool result, or null if
     * the result does not represent an error.
     */
    private static function extractToolError($result): ?string
    {
        if (!is_array($result) || !array_key_exists('error', $result) || $result['error'] === false) {
            return null;
        }
        if (is_string($result['error']) && $result['error'] !== '') {
            return $result['error'];
        }
        if (isset($result['message']) && is_string($result['message']) && $result['message'] !== '') {
            return $result['message'];
        }
        return 'Unknown tool error';
    }

    /**
     * Record a tool error into the dedicated `alfred_tool_error` log table.
     */
    public static function logToolError(string $sessionId, string $toolName, array $arguments, string $errorMessage): void
    {
        DB::Prepare(
            'INSERT INTO alfred_tool_error (session_id, tool_name, arguments, error_message)
             VALUES (:session_id, :tool_name, :arguments, :error_message)',
            [
                ':session_id'    => $sessionId,
                ':tool_name'     => $toolName,
                ':arguments'     => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                ':error_message' => $errorMessage,
            ],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Auto-generate a title from the first user message (truncated).
     */
    public static function autoTitle(string $text, int $maxLen = 60): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($text) > $maxLen
            ? mb_substr($text, 0, $maxLen - 1) . '…'
            : $text;
    }
}
