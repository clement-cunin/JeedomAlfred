<?php

class alfredJournal
{
    /**
     * Jeedom daily cron hook.
     * Summarize yesterday's conversations for each active user and store as a memory entry.
     */
    public static function cronDaily(): void
    {
        if (!(bool)config::byKey('journal_daily_enabled', 'alfred')) {
            return;
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        log::add('alfred_cron', 'info', "journal: generating daily entries for {$yesterday}");

        $users = self::getActiveUsersForDate($yesterday);
        if (empty($users)) {
            log::add('alfred_cron', 'info', "journal: no active users for {$yesterday}");
            return;
        }

        foreach ($users as $login) {
            try {
                self::generateJournalEntry($login, $yesterday);
            } catch (Exception $e) {
                log::add('alfred_cron', 'error', "journal: failed for user {$login} on {$yesterday}: " . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------

    private static function getActiveUsersForDate(string $date): array
    {
        $rows = DB::Prepare(
            'SELECT DISTINCT c.user_login
             FROM alfred_conversation c
             JOIN alfred_message m ON m.session_id = c.session_id
             WHERE c.user_login IS NOT NULL
               AND m.role IN (\'user\', \'assistant\')
               AND DATE(m.created_at) = :date',
            [':date' => $date],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        return array_column($rows, 'user_login');
    }

    /**
     * Run journal generation for all active users on $date.
     * Returns per-user results (prompt, transcript, LLM output).
     */
    public static function runForDate(string $date, string $promptOverride = ''): array
    {
        $users   = self::getActiveUsersForDate($date);
        $results = [];
        foreach ($users as $login) {
            $results[] = self::generateJournalEntry($login, $date, $promptOverride);
        }
        return $results;
    }

    public static function generateJournalEntry(string $login, string $date, string $promptOverride = ''): array
    {
        $prompt = $promptOverride !== '' ? $promptOverride : (string)config::byKey('journal_daily_prompt', 'alfred');
        if ($prompt === '') {
            $prompt = self::defaultPrompt();
        }

        $transcript = self::buildTranscript($login, $date);
        if ($transcript === '') {
            log::add('alfred_cron', 'info', "journal: empty transcript for user {$login} on {$date}");
            return ['login' => $login, 'date' => $date, 'prompt' => $prompt, 'transcript' => '', 'result' => null, 'skipped' => true];
        }

        $memories     = alfredMemory::loadForUser($login);
        $systemPrompt = '';
        if (!empty($memories)) {
            $systemPrompt = "## Persistent memory\n";
            foreach ($memories as $m) {
                $heading       = $m['label'] !== '' ? $m['label'] : (($m['scope'] === 'global' ? 'global' : 'personal') . '-' . $m['id']);
                $systemPrompt .= "\n### {$heading}\n{$m['content']}\n";
            }
        }

        $llm      = alfredLLM::make();
        $messages = [['role' => 'user', 'content' => $prompt . "\n\n" . $transcript]];
        $response = $llm->chat($messages, [], $systemPrompt);

        $content = trim($response['text'] ?? '');
        if ($content === '') {
            log::add('alfred_cron', 'warning', "journal: LLM returned empty response for user {$login} on {$date}");
            return ['login' => $login, 'date' => $date, 'prompt' => $prompt, 'transcript' => $transcript, 'result' => null, 'skipped' => false];
        }

        $expiryDays = (int)config::byKey('journal_daily_expiry_days', 'alfred');
        $expiresAt  = ($expiryDays > 0)
            ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"))
            : null;

        $label    = "journal-{$login}-{$date}";
        $existing = alfredMemory::getByLabel($label);
        if ($existing !== null) {
            alfredMemory::adminUpdate($existing['id'], $label, $content, 'user:' . $login);
        } else {
            alfredMemory::save('user:' . $login, $label, $content, $expiresAt);
        }

        log::add('alfred_cron', 'info', "journal: entry saved for user {$login} ({$date})");
        return ['login' => $login, 'date' => $date, 'prompt' => $prompt, 'transcript' => $transcript, 'result' => $content, 'skipped' => false];
    }

    private static function buildTranscript(string $login, string $date): string
    {
        $sessions = DB::Prepare(
            'SELECT DISTINCT c.session_id, c.title, c.created_at
             FROM alfred_conversation c
             JOIN alfred_message m ON m.session_id = c.session_id
             WHERE c.user_login = :login
               AND m.role IN (\'user\', \'assistant\')
               AND DATE(m.created_at) = :date
             ORDER BY c.created_at ASC',
            [':login' => $login, ':date' => $date],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        if (empty($sessions)) {
            return '';
        }

        $parts = [];
        foreach ($sessions as $session) {
            $messages = DB::Prepare(
                'SELECT role, content, created_at FROM alfred_message
                 WHERE session_id = :session_id
                   AND role IN (\'user\', \'assistant\')
                   AND DATE(created_at) = :date
                 ORDER BY id ASC',
                [':session_id' => $session['session_id'], ':date' => $date],
                DB::FETCH_TYPE_ALL
            ) ?: [];

            if (empty($messages)) continue;

            $title = ($session['title'] !== '' && $session['title'] !== null)
                ? $session['title']
                : substr($session['session_id'], 0, 8);
            $time  = substr($session['created_at'], 0, 16);

            $block = "=== [{$time}] {$title} ===\n";
            foreach ($messages as $msg) {
                $ts      = substr($msg['created_at'], 11, 5);
                $role    = $msg['role'] === 'user' ? 'User' : 'Alfred';
                $content = $msg['content'];
                // Content may be JSON-encoded — decode for readability
                $decoded = json_decode($content, true);
                if (is_string($decoded)) {
                    $content = $decoded;
                } elseif (is_array($decoded)) {
                    $content = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $block .= "[{$ts}] {$role}: {$content}\n";
            }
            $parts[] = $block;
        }

        return implode("\n\n", $parts);
    }

    private static function defaultPrompt(): string
    {
        return 'Below is a transcript of conversations between a user and Alfred (an AI home assistant) from yesterday.'
            . ' Write a concise memory note (3-7 sentences) summarizing:'
            . ' the main topics discussed, any decisions or instructions given,'
            . ' preferences or habits expressed, and anything useful for future context.'
            . ' Write in third person about the user. No preamble or headings.';
    }
}
