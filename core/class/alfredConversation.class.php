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
     * Load messages for a session as internal Alfred format (for LLM context).
     * Error messages are excluded — they are display-only and must not pollute the LLM context.
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

            // Skip error messages — they are display-only
            if (!empty($meta['error'])) continue;

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
            }
            if ($row['role'] === 'tool') {
                $msg['tool_call_id'] = $meta['tool_call_id'] ?? '';
                $msg['name']         = $meta['name'] ?? '';
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
              system_chars, history_chars, tools_chars, new_res_chars,
              router_strategy, tools_total_count, tools_offered_count, router_categories)
             VALUES
             (:session_id, :message_id, :iteration, :provider, :model,
              :input_tokens, :output_tokens, :duration_ms,
              :system_chars, :history_chars, :tools_chars, :new_res_chars,
              :router_strategy, :tools_total_count, :tools_offered_count, :router_categories)',
            [
                ':session_id'          => $sessionId,
                ':message_id'          => $messageId ?: null,
                ':iteration'           => (int)($data['iteration']           ?? 1),
                ':provider'            => (string)($data['provider']         ?? ''),
                ':model'               => (string)($data['model']            ?? ''),
                ':input_tokens'        => (int)($data['input_tokens']        ?? 0),
                ':output_tokens'       => (int)($data['output_tokens']       ?? 0),
                ':duration_ms'         => (int)($data['duration_ms']         ?? 0),
                ':system_chars'        => (int)($data['system_chars']        ?? 0),
                ':history_chars'       => (int)($data['history_chars']       ?? 0),
                ':tools_chars'         => (int)($data['tools_chars']         ?? 0),
                ':new_res_chars'       => (int)($data['new_res_chars']       ?? 0),
                ':router_strategy'     => (string)($data['router_strategy']     ?? 'A'),
                ':tools_total_count'   => (int)($data['tools_total_count']      ?? 0),
                ':tools_offered_count' => (int)($data['tools_offered_count']    ?? 0),
                ':router_categories'   => (string)($data['router_categories']   ?? ''),
            ],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Persist a tool result into the session.
     */
    public static function saveToolResult(string $sessionId, string $toolCallId, string $toolName, $result): void
    {
        $content = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string)$result;
        self::addMessage($sessionId, 'tool', $content, [
            'tool_call_id' => $toolCallId,
            'name'         => $toolName,
        ]);
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
