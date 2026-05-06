<?php

class alfredLLMOllamaAdapter extends alfredLLMAdapter
{
    // $apiKey is repurposed to store the Ollama base URL (e.g. http://localhost:11434)

    private function chatUrl(): string { return rtrim($this->apiKey, '/') . '/v1/chat/completions'; }
    private function tagsUrl(): string { return rtrim($this->apiKey, '/') . '/api/tags'; }

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $body = $this->buildBody($messages, $tools, $systemPrompt, false);
        $data = $this->httpPost($this->chatUrl(), $this->headers(), $body, 120);
        return $this->normalize($data);
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $body        = $this->buildBody($messages, $tools, $systemPrompt, true);
        $text        = '';
        $tool_acc    = [];
        $stop_reason = 'end_turn';
        $buffer      = '';
        $error_body  = '';

        $ch = curl_init($this->chatUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
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
                        if (isset($tc['id']))                    $tool_acc[$idx]['id']        = $tc['id'];
                        if (isset($tc['function']['name']))      $tool_acc[$idx]['name']      = $tc['function']['name'];
                        if (isset($tc['function']['arguments'])) $tool_acc[$idx]['arguments'] .= $tc['function']['arguments'];
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
            throw new Exception("Cannot connect to Ollama: {$err}");
        }
        if ($code >= 400) {
            if ($buffer !== '') $error_body .= trim($buffer);
            if ($error_body !== '') {
                $parsed = json_decode($error_body, true);
                if (is_array($parsed)) {
                    $error_body = $parsed['message'] ?? $parsed['error']['message'] ?? $error_body;
                }
            }
            throw new Exception("Ollama error (HTTP {$code}): " . ($error_body ?: "HTTP {$code}"));
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
        $this->httpPost($this->chatUrl(), $this->headers(), $body, 15);
        return ['ok' => true, 'provider' => 'ollama', 'model' => $this->model];
    }

    public function listModels(): array
    {
        $ch = curl_init($this->tagsUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception("Cannot connect to Ollama at {$this->apiKey}: {$err}");
        }
        if ($code >= 400) {
            throw new Exception("Ollama tags API error (HTTP {$code})");
        }

        $data = json_decode($raw, true);
        if (!isset($data['models'])) {
            return [];
        }

        $models = [];
        foreach ($data['models'] as $m) {
            $name = $m['name'] ?? '';
            if ($name === '') continue;
            $size      = $m['size'] ?? 0;
            $sizeLabel = $size > 0 ? ' — ' . round($size / 1e9, 1) . ' GB' : '';
            $models[]  = ['id' => $name, 'name' => $name . $sizeLabel];
        }
        usort($models, function ($a, $b) { return strcmp($a['id'], $b['id']); });
        return $models;
    }

    // -------------------------------------------------------------------------

    private function headers(): array
    {
        return ['Content-Type: application/json'];
    }

    private function buildBody(array $messages, array $tools, string $systemPrompt, bool $stream): array
    {
        $body = [
            'model'    => $this->model,
            'messages' => $this->toMessages($messages, $systemPrompt),
            'stream'   => $stream,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->toTools($tools);
        }
        return $body;
    }

    private function toMessages(array $messages, string $systemPrompt): array
    {
        $out = [];
        if ($systemPrompt !== '') {
            $out[] = ['role' => 'system', 'content' => $systemPrompt];
        }
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

    private function toTools(array $tools): array
    {
        return array_map(function ($t) {
            $schema = $t['inputSchema'] ?? ['type' => 'object', 'properties' => []];
            return [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters'  => $schema,
                ],
            ];
        }, $tools);
    }

    private function normalize(array $data): array
    {
        $choice     = $data['choices'][0] ?? [];
        $message    = $choice['message'] ?? [];
        $text       = $message['content'] ?? '';
        $tool_calls = [];

        foreach ($message['tool_calls'] ?? [] as $tc) {
            $input        = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $tool_calls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['function']['name'],
                'input' => $input,
            ];
        }

        $finishReason = $choice['finish_reason'] ?? 'stop';
        $u            = $data['usage'] ?? [];

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
