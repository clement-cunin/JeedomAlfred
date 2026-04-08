<?php

class alfredLLMGeminiAdapter extends alfredLLMAdapter
{
    private const API_BASE   = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $url  = self::API_BASE . '/' . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);
        $body = ['contents' => $this->toGeminiContents($messages)];
        if ($systemPrompt !== '') {
            $body['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        if (!empty($tools)) {
            $body['tools'] = [['function_declarations' => $this->toGeminiFunctions($tools)]];
        }

        $data = $this->httpPost($url, ['Content-Type: application/json'], $body);

        return $this->normalize($data);
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        // Gemini has no SSE streaming for generateContent — call regular endpoint
        // and simulate chunk-by-chunk delivery by splitting the response into words.
        $response = $this->chat($messages, $tools, $systemPrompt);

        if ($response['text'] !== '') {
            // Split on whitespace, preserving delimiters so spacing is retained
            $parts = preg_split('/(\s+)/', $response['text'], -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $part) {
                if ($part !== '') {
                    $onDelta($part);
                }
            }
        }

        return $response;
    }

    public function testConnection(): array
    {
        $url  = self::API_BASE . '/' . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);
        $body = ['contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]]];
        $this->httpPost($url, ['Content-Type: application/json'], $body, 15);
        return ['ok' => true, 'provider' => 'gemini', 'model' => $this->model];
    }

    public function listModels(): array
    {
        $url = self::MODELS_URL . '?key=' . urlencode($this->apiKey) . '&pageSize=100';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if ($code >= 400 || !isset($data['models'])) {
            throw new Exception('Gemini models API error (HTTP ' . $code . ')');
        }

        $models = [];
        foreach ($data['models'] as $m) {
            // Must support generateContent
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (!in_array('generateContent', $methods, true)) continue;

            $id = preg_replace('#^models/#', '', $m['name'] ?? '');
            if ($id === '') continue;

            // Must start with gemini-X.Y-(pro|flash)
            if (!preg_match('/^gemini-\d+\.\d+-(pro|flash)/', $id)) continue;

            $displayName = $m['displayName'] ?? $id;

            // Exclude non-chat sub-variants — check both ID and display name
            $excludePattern = '/nano|banana|lite|embedding|vision|aqa|thinking-exp/i';
            if (preg_match($excludePattern, $id) || preg_match($excludePattern, $displayName)) continue;

            $models[] = ['id' => $id, 'name' => $displayName];
        }
        usort($models, fn($a, $b) => strcmp($b['id'], $a['id']));
        return $models;
    }

    // -------------------------------------------------------------------------

    private function toGeminiContents(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'user') {
                $out[] = ['role' => 'user', 'parts' => [['text' => $msg['content']]]];

            } elseif ($role === 'assistant') {
                $parts = [];
                if (!empty($msg['content'])) {
                    $parts[] = ['text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] ?? [] as $tc) {
                    // args must be a JSON object, never an array
                    $args    = empty($tc['input']) ? new stdClass() : (object)$tc['input'];
                    $parts[] = ['functionCall' => ['name' => $tc['name'], 'args' => $args]];
                }
                $out[] = ['role' => 'model', 'parts' => $parts];

            } elseif ($role === 'tool') {
                $result = json_decode($msg['content'], true) ?? $msg['content'];
                $out[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => $msg['name'],
                            'response' => ['content' => $result],
                        ],
                    ]],
                ];
            }
        }
        return $out;
    }

    private function toGeminiFunctions(array $tools): array
    {
        return array_map(function ($t) {
            $schema = $t['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass()];
            $schema = $this->sanitizeSchema($schema);
            return [
                'name'        => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters'  => $schema,
            ];
        }, $tools);
    }

    /**
     * Recursively sanitize a JSON Schema for Gemini:
     * - Empty properties array → stdClass
     * - Array type with missing/empty items → items: {type: string}
     * - Remove unsupported keywords (default, examples, $schema, additionalProperties)
     */
    private function sanitizeSchema($schema): array
    {
        if (!is_array($schema)) {
            return ['type' => 'string'];
        }

        // Remove keywords Gemini does not support
        foreach (['default', 'examples', '$schema', 'additionalProperties', '$defs', 'definitions'] as $key) {
            unset($schema[$key]);
        }

        // Fix empty properties
        if (isset($schema['properties']) && empty($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }

        // Recurse into properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $k => $v) {
                $schema['properties'][$k] = $this->sanitizeSchema($v);
            }
        }

        // Fix array items
        if (($schema['type'] ?? '') === 'array') {
            if (empty($schema['items'])) {
                $schema['items'] = ['type' => 'string'];
            } else {
                $schema['items'] = $this->sanitizeSchema($schema['items']);
            }
        }

        return $schema;
    }

    private function normalize(array $data): array
    {
        $candidate  = $data['candidates'][0] ?? [];
        $parts      = $candidate['content']['parts'] ?? [];
        $text       = '';
        $tool_calls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                // Skip internal reasoning (thinking models return thought: true)
                if (!empty($part['thought'])) continue;
                $text .= $part['text'];
            } elseif (isset($part['functionCall'])) {
                $tool_calls[] = [
                    'id'    => uniqid('gemini_', true), // Gemini has no call IDs
                    'name'  => $part['functionCall']['name'],
                    'input' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        $finishReason = $candidate['finishReason'] ?? 'STOP';

        return [
            'text'        => trim($text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => !empty($tool_calls) ? 'tool_use' : 'end_turn',
        ];
    }
}
