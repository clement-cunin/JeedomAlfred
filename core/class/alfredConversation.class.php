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

    public static function createSession(string $sessionId, string $title = ''): void
    {
        $db = DB::getLastInsertId();
        DB::Prepare(
            'INSERT INTO alfred_conversation (session_id, title) VALUES (:session_id, :title)',
            [':session_id' => $sessionId, ':title' => $title],
            DB::FETCH_TYPE_ROW
        );
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

    public static function listSessions(int $limit = 50): array
    {
        $n = max(1, (int)$limit);
        return DB::Prepare(
            "SELECT * FROM alfred_conversation ORDER BY updated_at DESC LIMIT {$n}",
            [],
            DB::FETCH_TYPE_ALL
        ) ?: [];
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
    public static function addMessage(string $sessionId, string $role, $content, array $metadata = []): void
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

        self::touchSession($sessionId);
    }

    /**
     * Load all messages for a session as internal Alfred format.
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
     * Persist a full normalized LLM response into the session.
     *
     * $response = ['text' => '...', 'tool_calls' => [...], 'stop_reason' => '...']
     */
    public static function saveAssistantResponse(string $sessionId, array $response): void
    {
        $meta = [];
        if (!empty($response['tool_calls'])) {
            $meta['tool_calls'] = $response['tool_calls'];
        }
        if (!empty($response['gemini_thought_parts'])) {
            $meta['gemini_thought_parts'] = $response['gemini_thought_parts'];
        }
        self::addMessage($sessionId, 'assistant', $response['text'], $meta);
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
