<?php

class alfredLLMMistralAdapter extends alfredLLMAdapter
{
    private const API_URL    = 'https://api.mistral.ai/v1/chat/completions';
    private const MODELS_URL = 'https://api.mistral.ai/v1/models';

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $mistralMessages = [];
        if ($systemPrompt !== '') {
            $mistralMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($this->toMistralMessages($messages) as $m) {
            $mistralMessages[] = $m;
        }

        $body = [
            'model'    => $this->model,
            'messages' => $mistralMessages,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->toMistralTools($tools);
        }

        $data = $this->httpPost(self::API_URL, $this->headers(), $body, 120);

        return $this->normalize($data);
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $mistralMessages = [];
        if ($systemPrompt !== '') {
            $mistralMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($this->toMistralMessages($messages) as $m) {
            $mistralMessages[] = $m;
        }

        $body = [
            'model'    => $this->model,
            'messages' => $mistralMessages,
            'stream'   => true,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->toMistralTools($tools);
        }

        $text        = '';
        $tool_acc    = []; // index => ['id', 'name', 'arguments']
        $stop_reason = 'end_turn';
        $buffer      = '';
        $error_body  = '';

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION  => function ($ch, $raw) use (&$buffer, &$text, &$tool_acc, &$stop_reason, &$error_body, $onDelta) {
                $buffer .= $raw;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || $line === 'data: [DONE]') continue;
                    if (substr($line, 0, 6) !== 'data: ') {
                        $error_body .= $line;
                        continue;
                    }

                    $d = json_decode(substr($line, 6), true);
                    if (!is_array($d)) continue;

                    if (isset($d['error'])) {
                        $error_body = $d['error']['message'] ?? json_encode($d['error']);
                        continue;
                    }

                    $choice = $d['choices'][0] ?? [];
                    $delta  = $choice['delta'] ?? [];
                    $finish = $choice['finish_reason'] ?? null;

                    if ($finish === 'tool_calls') {
                        $stop_reason = 'tool_use';
                    }

                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $chunk  = $delta['content'];
                        $text  .= $chunk;
                        $onDelta($chunk);
                    }

                    foreach ($delta['tool_calls'] ?? [] as $tc) {
                        $idx = $tc['index'];
                        if (!isset($tool_acc[$idx])) {
                            $tool_acc[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                        }
                        if (isset($tc['id']))                     $tool_acc[$idx]['id']        = $tc['id'];
                        if (isset($tc['function']['name']))       $tool_acc[$idx]['name']      = $tc['function']['name'];
                        if (isset($tc['function']['arguments']))  $tool_acc[$idx]['arguments'] .= $tc['function']['arguments'];
                    }
                }
                return strlen($raw);
            },
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception("HTTP request failed: {$err}");
        }
        if ($code >= 400) {
            // Flush any partial buffer (error body without trailing newline)
            if ($buffer !== '') {
                $error_body .= trim($buffer);
            }
            // Extract message from JSON error body if possible
            if ($error_body !== '') {
                $parsed = json_decode($error_body, true);
                if (is_array($parsed)) {
                    $error_body = $parsed['message'] ?? $parsed['error']['message'] ?? $error_body;
                }
            }
            $msg = $error_body !== '' ? $error_body : "HTTP {$code}";
            throw new Exception("Mistral API error: {$msg}");
        }

        $tool_calls = [];
        ksort($tool_acc);
        foreach ($tool_acc as $tc) {
            $tool_calls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['name'],
                'input' => json_decode($tc['arguments'], true) ?? [],
            ];
        }

        return [
            'text'        => trim($text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => $stop_reason,
        ];
    }

    public function testConnection(): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Hi']],
        ];
        $this->httpPost(self::API_URL, $this->headers(), $body, 15);
        return ['ok' => true, 'provider' => 'mistral', 'model' => $this->model];
    }

    public function listModels(): array
    {
        $ch = curl_init(self::MODELS_URL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if ($code >= 400 || !isset($data['data'])) {
            throw new Exception('Mistral models API error (HTTP ' . $code . ')');
        }

        $active     = [];
        $deprecated = [];
        foreach ($data['data'] as $m) {
            $id = $m['id'] ?? '';
            if (!($m['capabilities']['completion_chat'] ?? false)) continue;
            if (!($m['capabilities']['function_calling'] ?? false)) continue;

            $ctx        = $m['max_context_length'] ?? 0;
            $ctxLabel   = $ctx >= 1000000 ? round($ctx / 1000000) . 'M ctx'
                        : ($ctx >= 1000   ? round($ctx / 1000)     . 'k ctx' : '');
            $deprDate   = $m['deprecation'] ?? null;

            if ($deprDate) {
                $dateShort = substr($deprDate, 0, 10); // YYYY-MM-DD
                $label     = $id . ' — deprecated ' . $dateShort . ($ctxLabel ? ' — ' . $ctxLabel : '');
                $deprecated[] = ['id' => $id, 'name' => $label];
            } else {
                $label    = $id . ($ctxLabel ? ' — ' . $ctxLabel : '');
                $active[] = ['id' => $id, 'name' => $label];
            }
        }
        usort($active,     function ($a, $b) { return strcmp($b['id'], $a['id']); });
        usort($deprecated, function ($a, $b) { return strcmp($b['id'], $a['id']); });
        return array_merge($active, $deprecated);
    }

    // -------------------------------------------------------------------------

    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
    }

    private function toMistralMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'user') {
                $out[] = ['role' => 'user', 'content' => $msg['content']];

            } elseif ($role === 'assistant') {
                $m = ['role' => 'assistant', 'content' => $msg['content'] ?? null];
                if (!empty($msg['tool_calls'])) {
                    $m['tool_calls'] = array_map(function ($tc) {
                        return [
                            'id'       => $tc['id'],
                            'type'     => 'function',
                            'function' => [
                                'name'      => $tc['name'],
                                'arguments' => json_encode($tc['input']),
                            ],
                        ];
                    }, $msg['tool_calls']);
                }
                $out[] = $m;

            } elseif ($role === 'tool') {
                $out[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $msg['tool_call_id'],
                    'content'      => $msg['content'],
                    'name'         => $msg['name'] ?? '',
                ];
            }
        }
        return $out;
    }

    private function toMistralTools(array $tools): array
    {
        return array_map(function ($t) {
            $schema = $t['inputSchema'] ?? ['type' => 'object', 'properties' => []];
            return [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters'  => $this->normalizeSchemaForJson($schema),
                ],
            ];
        }, $tools);
    }

    /**
     * Recursively convert PHP arrays to proper JSON types.
     *
     * json_decode($json, true) turns {} into [] in PHP. Re-encoding that gives []
     * instead of {}, which fails JSON Schema validation. This converts:
     *   - empty arrays → stdClass (encodes as {})
     *   - associative arrays → stdClass (encodes as JSON object)
     *   - sequential arrays → plain array (encodes as JSON array)
     */
    private static $JSON_ARRAY_KEYS = ['required', 'enum', 'anyOf', 'oneOf', 'allOf', 'not'];

    private function normalizeSchemaForJson($value, string $parentKey = '')
    {
        if (!is_array($value)) {
            return $value;
        }
        // Keys that must stay as JSON arrays even when empty
        if (in_array($parentKey, self::$JSON_ARRAY_KEYS, true)) {
            return array_map(function ($v) { return $this->normalizeSchemaForJson($v); }, $value);
        }
        if (empty($value)) {
            return new stdClass();
        }
        $keys = array_keys($value);
        if ($keys === range(0, count($keys) - 1)) {
            // Sequential array → JSON array
            return array_map(function ($v) { return $this->normalizeSchemaForJson($v); }, $value);
        }
        // Associative array → JSON object
        $obj = new stdClass();
        foreach ($value as $k => $v) {
            $obj->$k = $this->normalizeSchemaForJson($v, $k);
        }
        return $obj;
    }

    private function normalize(array $data): array
    {
        $choice     = $data['choices'][0] ?? [];
        $message    = $choice['message'] ?? [];
        $text       = $message['content'] ?? '';
        $tool_calls = [];

        foreach ($message['tool_calls'] ?? [] as $tc) {
            $input = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $tool_calls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['function']['name'],
                'input' => $input,
            ];
        }

        $finishReason = $choice['finish_reason'] ?? 'stop';

        $u = $data['usage'] ?? [];
        return [
            'text'        => trim((string)$text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => $finishReason === 'tool_calls' ? 'tool_use' : 'end_turn',
            'usage'       => [
                'input_tokens'  => (int)($u['prompt_tokens']     ?? 0),
                'output_tokens' => (int)($u['completion_tokens'] ?? 0),
            ],
        ];
    }
}
