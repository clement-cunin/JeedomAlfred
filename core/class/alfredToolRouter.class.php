<?php

class alfredToolRouter
{
    // Default keywords per category — used when DB is empty (no explicit config yet)
    private static $DEFAULT_KEYWORDS = [
        'ALFRED'        => ['memory', 'remember', 'note', 'schedule', 'remind', 'file', 'upload'],
        'JEEDOM_CORE'   => ['device', 'light', 'lumiere', 'lumière', 'switch', 'temperature', 'température',
                            'sensor', 'room', 'pièce', 'piece', 'scenario', 'turn on', 'turn off',
                            'allume', 'éteins', 'status', 'state', 'home', 'equipment', 'command', 'jeedom'],
        'DOCUMENTS'     => ['document', 'paperless', 'pdf', 'scan', 'scanner', 'invoice', 'facture',
                            'lettre', 'letter', 'correspondence', 'courrier', 'tag', 'archiv',
                            'numérisation', 'numériser'],
        'MEDIA'         => ['media', 'video', 'music', 'musique', 'download', 'télécharge', 'telecharge',
                            'film', 'série', 'serie', 'movie', 'torrent', 'radarr', 'sonarr'],
        'AUTOCLAUDE'    => ['ticket', 'issue', 'task', 'tâche', 'tache', 'board', 'project', 'development', 'bug'],
        'ZWAVE'         => ['zwave', 'z-wave', 'node', 'inclusion', 'exclusion', 'pairing', 'appairage'],
        'NOTIFICATIONS' => ['notification', 'push', 'mobile', 'alert', 'geofence', 'localisation', 'location'],
        'WEATHER'       => ['météo', 'meteo', 'weather', 'temps', 'forecast', 'prévision', 'prevision',
                            'pluie', 'soleil', 'température extérieure', 'vent', 'humidité'],
        'ADMIN'         => ['plugin', 'update', 'mise à jour', 'log', 'message', 'system', 'install'],
    ];

    // Tool name prefix/pattern → category mapping (first match wins)
    private static $CATEGORY_PATTERNS = [
        'ALFRED'        => '/^(alfred_|file_create$|uploaded_file_read$)/',
        'JEEDOM_CORE'   => '/^(device|devices|room|rooms|command|scenario|scenarios|acl_)/',
        'DOCUMENTS'     => '/^(ext_jaganin_paperless_|ext_jaganin_scanner_)/',
        'MEDIA'         => '/^ext_jaganin_media_/',
        'AUTOCLAUDE'    => '/^ext_jaganin_autoclaude_/',
        'ZWAVE'         => '/^ext_openzwave_/',
        'NOTIFICATIONS' => '/^ext_JeedomConnect_/',
        'WEATHER'       => '/^ext_weather_/',
        'ADMIN'         => '/^(plugin_|update_|message_|log_|ext_jaganin_plugin_|ext_MerosSync_)/',
    ];

    /**
     * Main entry point. Routes tools based on strategy and user message.
     *
     * Returns:
     *   tools          => filtered tool array to pass to the LLM
     *   total          => total tools before filtering
     *   offered        => tools actually sent
     *   categories     => comma-separated matched categories, or 'ALL'
     *   fallback       => true if strategy B fell back to sending all tools
     */
    public static function route(array $allTools, string $userMessage, string $strategy): array
    {
        $total = count($allTools);

        if ($strategy !== 'B') {
            return [
                'tools'      => $allTools,
                'total'      => $total,
                'offered'    => $total,
                'categories' => 'ALL',
                'fallback'   => false,
            ];
        }

        $categories = self::loadCategories();
        $matched    = self::matchCategories($userMessage, $categories);

        if (!in_array('ALFRED', $matched, true)) {
            $matched[] = 'ALFRED';
        }

        $nonAlfred         = array_values(array_filter($matched, function ($c) { return $c !== 'ALFRED'; }));
        $totalNonAlfredCats = count(array_filter(array_keys($categories), function ($c) { return $c !== 'ALFRED'; }));

        // Fallback: no domain category matched, or all domain categories matched
        $fallback = (count($nonAlfred) === 0 || count($nonAlfred) >= $totalNonAlfredCats);

        if ($fallback) {
            return [
                'tools'      => $allTools,
                'total'      => $total,
                'offered'    => $total,
                'categories' => 'ALL',
                'fallback'   => true,
            ];
        }

        $filtered = array_values(array_filter($allTools, function ($tool) use ($matched) {
            return in_array(self::deriveCategory($tool['name']), $matched, true);
        }));

        return [
            'tools'      => $filtered,
            'total'      => $total,
            'offered'    => count($filtered),
            'categories' => implode(',', $matched),
            'fallback'   => false,
        ];
    }

    /**
     * Derive the category for a tool name based on its prefix.
     */
    public static function deriveCategory(string $toolName): string
    {
        foreach (self::$CATEGORY_PATTERNS as $category => $pattern) {
            if (preg_match($pattern, $toolName)) {
                return $category;
            }
        }
        return 'OTHER';
    }

    /**
     * Load categories and keywords from DB. Falls back to defaults if table is empty.
     * Returns ['CATEGORY' => ['kw1', 'kw2', ...], ...]
     */
    public static function loadCategories(): array
    {
        $rows = DB::Prepare(
            'SELECT category, keywords FROM alfred_tool_category ORDER BY category ASC',
            [], DB::FETCH_TYPE_ALL
        ) ?: [];

        if (empty($rows)) {
            return self::$DEFAULT_KEYWORDS;
        }

        $out = [];
        foreach ($rows as $row) {
            $kws = array_values(array_filter(
                array_map('trim', explode(',', $row['keywords']))
            ));
            $out[$row['category']] = $kws;
        }
        return $out;
    }

    /**
     * Match categories whose keywords appear in the user message.
     * Returns list of matched category names.
     */
    public static function matchCategories(string $message, array $categories): array
    {
        $msgLower = mb_strtolower($message);
        $matched  = [];
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($msgLower, mb_strtolower($kw)) !== false) {
                    $matched[] = $category;
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * Seed the alfred_tool_category table with default keywords for categories not yet present.
     */
    public static function seedDefaultCategories(): void
    {
        foreach (self::$DEFAULT_KEYWORDS as $category => $keywords) {
            $existing = DB::Prepare(
                'SELECT id FROM alfred_tool_category WHERE category = :cat LIMIT 1',
                [':cat' => $category], DB::FETCH_TYPE_ROW
            );
            if (!$existing) {
                DB::Prepare(
                    'INSERT INTO alfred_tool_category (category, keywords) VALUES (:cat, :kws)',
                    [':cat' => $category, ':kws' => implode(', ', $keywords)],
                    DB::FETCH_TYPE_ROW
                );
            }
        }
    }

    /**
     * Save the full categories array to DB (upsert).
     * $data = ['CATEGORY' => 'kw1, kw2, kw3', ...]
     */
    public static function saveCategories(array $data): void
    {
        foreach ($data as $category => $keywords) {
            $kws      = is_array($keywords) ? implode(', ', $keywords) : (string)$keywords;
            $existing = DB::Prepare(
                'SELECT id FROM alfred_tool_category WHERE category = :cat LIMIT 1',
                [':cat' => $category], DB::FETCH_TYPE_ROW
            );
            if ($existing) {
                DB::Prepare(
                    'UPDATE alfred_tool_category SET keywords = :kws WHERE category = :cat',
                    [':kws' => $kws, ':cat' => $category],
                    DB::FETCH_TYPE_ROW
                );
            } else {
                DB::Prepare(
                    'INSERT INTO alfred_tool_category (category, keywords) VALUES (:cat, :kws)',
                    [':cat' => $category, ':kws' => $kws],
                    DB::FETCH_TYPE_ROW
                );
            }
        }
    }

    /**
     * Run backtest on historical conversations.
     *
     * For each user turn that resulted in tool calls, simulates what categories
     * would be matched by the current keyword config, then checks whether all
     * actually called tools would have been included in the offered set.
     *
     * Returns:
     *   sessions_tested  => sessions with at least one turn that used tools
     *   turns_tested     => number of user→tool turns analyzed
     *   tools_called     => total tool invocations across all turns
     *   recall           => fraction of tool calls whose category would have been offered
     *   misses           => up to 50 examples where a tool's category was not matched
     */
    public static function backtest(): array
    {
        $sessions = DB::Prepare(
            'SELECT DISTINCT m.session_id
             FROM alfred_message m
             INNER JOIN alfred_message t ON t.session_id = m.session_id AND t.role = \'tool\'
             WHERE m.role = \'user\'
             ORDER BY m.session_id',
            [], DB::FETCH_TYPE_ALL
        ) ?: [];

        $categories = self::loadCategories();

        $totalUsed      = 0;
        $hitCount       = 0;
        $totalTurns     = 0;
        $fallbackTurns  = 0;
        $sessionsTested = 0;
        $misses         = [];

        foreach ($sessions as $sess) {
            $sessionId = $sess['session_id'];
            $messages  = DB::Prepare(
                'SELECT role, content, metadata FROM alfred_message
                 WHERE session_id = :sid ORDER BY id ASC',
                [':sid' => $sessionId], DB::FETCH_TYPE_ALL
            ) ?: [];

            $turns       = [];
            $currentUser = null;
            $toolsCalled = [];

            foreach ($messages as $msg) {
                $meta = $msg['metadata'] ? json_decode($msg['metadata'], true) : [];
                if ($msg['role'] === 'user') {
                    if ($currentUser !== null && !empty($toolsCalled)) {
                        $turns[] = ['user' => $currentUser, 'tools' => $toolsCalled];
                    }
                    $currentUser = $msg['content'];
                    $toolsCalled = [];
                } elseif ($msg['role'] === 'tool') {
                    $name = $meta['name'] ?? '';
                    // Exclude synthetic tools — they are always sent
                    if ($name !== ''
                        && strpos($name, 'alfred_') !== 0
                        && $name !== 'file_create'
                        && $name !== 'uploaded_file_read') {
                        $toolsCalled[] = $name;
                    }
                }
            }
            if ($currentUser !== null && !empty($toolsCalled)) {
                $turns[] = ['user' => $currentUser, 'tools' => $toolsCalled];
            }

            if (empty($turns)) continue;
            $sessionsTested++;

            $totalNonAlfredCats = count(array_filter(array_keys($categories), function ($c) { return $c !== 'ALFRED'; }));

            foreach ($turns as $turn) {
                $totalTurns++;
                $matched = self::matchCategories($turn['user'], $categories);
                if (!in_array('ALFRED', $matched, true)) {
                    $matched[] = 'ALFRED';
                }
                $nonAlfred = array_values(array_filter($matched, function ($c) { return $c !== 'ALFRED'; }));
                $fallback  = (count($nonAlfred) === 0 || count($nonAlfred) >= $totalNonAlfredCats);
                if ($fallback) $fallbackTurns++;

                foreach ($turn['tools'] as $toolName) {
                    $cat = self::deriveCategory($toolName);
                    $totalUsed++;
                    if ($fallback || in_array($cat, $matched, true)) {
                        $hitCount++;
                    } else {
                        if (count($misses) < 50) {
                            $misses[] = [
                                'session_id'      => $sessionId,
                                'user_message'    => mb_substr($turn['user'], 0, 120),
                                'missed_tool'     => $toolName,
                                'missed_category' => $cat,
                            ];
                        }
                    }
                }
            }
        }

        return [
            'sessions_tested' => $sessionsTested,
            'turns_tested'    => $totalTurns,
            'fallback_turns'  => $fallbackTurns,
            'fallback_rate'   => ($totalTurns > 0) ? round($fallbackTurns / $totalTurns, 4) : 0.0,
            'tools_called'    => $totalUsed,
            'recall'          => ($totalUsed > 0) ? round($hitCount / $totalUsed, 4) : 1.0,
            'misses'          => $misses,
        ];
    }
}
