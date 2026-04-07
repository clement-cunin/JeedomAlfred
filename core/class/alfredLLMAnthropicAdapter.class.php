<?php

class alfredLLMAnthropicAdapter extends alfredLLMAdapter
{
    private const API_URL    = 'https://api.anthropic.com/v1/messages';
    private const MODELS_URL = 'https://api.anthropic.com/v1/models';
    private const VERSION    = '2023-06-01';

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'messages'   => $this->toAnthropicMessages($messages),
        ];
        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }
        if (!empty($tools)) {
            $body['tools'] = $this->toAnthropicTools($tools);
        }

        $data = $this->httpPost(self::API_URL, $this->headers(), $body);

        return $this->normalize($data);
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'stream'     => true,
            'messages'   => $this->toAnthropicMessages($messages),
        ];
        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }
        if (!empty($tools)) {
            $body['tools'] = $this->toAnthropicTools($tools);
        }

        $text        = '';
        $tool_blocks = []; // index => ['id', 'name', 'json']
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
            CURLOPT_WRITEFUNCTION  => function ($ch, $raw) use (&$buffer, &$text, &$tool_blocks, &$stop_reason, &$error_body, $onDelta) {
                $buffer .= $raw;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || substr($line, 0, 7) === 'event: ') continue;
                    if (substr($line, 0, 6) !== 'data: ') {
                        $error_body .= $line;
                        continue;
                    }

                    $d = json_decode(substr($line, 6), true);
                    if (!is_array($d)) continue;

                    $type = $d['type'] ?? '';

                    if ($type === 'content_block_start') {
                        $block = $d['content_block'] ?? [];
                        if (($block['type'] ?? '') === 'tool_use') {
                            $idx = $d['index'] ?? 0;
                            $tool_blocks[$idx] = [
                                'id'   => $block['id']   ?? '',
                                'name' => $block['name'] ?? '',
                                'json' => '',
                            ];
                        }
                    } elseif ($type === 'content_block_delta') {
                        $delta = $d['delta'] ?? [];
                        $idx   = $d['index'] ?? 0;
                        if (($delta['type'] ?? '') === 'text_delta') {
                            $chunk  = $delta['text'] ?? '';
                            $text  .= $chunk;
                            $onDelta($chunk);
                        } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                            if (isset($tool_blocks[$idx])) {
                                $tool_blocks[$idx]['json'] .= $delta['partial_json'] ?? '';
                            }
                        }
                    } elseif ($type === 'message_delta') {
                        $stop_reason = $d['delta']['stop_reason'] ?? 'end_turn';
                    } elseif ($type === 'error') {
                        $error_body = $d['error']['message'] ?? json_encode($d['error'] ?? $d);
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
            $msg = $error_body !== '' ? $error_body : "HTTP {$code}";
            throw new Exception("Anthropic API error: {$msg}");
        }

        $tool_calls = [];
        foreach ($tool_blocks as $block) {
            $tool_calls[] = [
                'id'    => $block['id'],
                'name'  => $block['name'],
                'input' => json_decode($block['json'], true) ?? [],
            ];
        }

        return [
            'text'        => trim($text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => ($stop_reason === 'tool_use') ? 'tool_use' : 'end_turn',
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
        return ['ok' => true, 'provider' => 'anthropic', 'model' => $this->model];
    }

    public function listModels(): array
    {
        $ch = curl_init(self::MODELS_URL . '?limit=100');
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
            throw new Exception('Anthropic models API error (HTTP ' . $code . ')');
        }

        $models = [];
        foreach ($data['data'] as $m) {
            $id = $m['id'] ?? '';
            if ($id === '') continue;
            $models[] = ['id' => $id, 'name' => $m['display_name'] ?? $id];
        }
        usort($models, fn($a, $b) => strcmp($b['id'], $a['id']));
        return $models;
    }

    // -------------------------------------------------------------------------

    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::VERSION,
        ];
    }

    private function toAnthropicMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'user') {
                $out[] = ['role' => 'user', 'content' => $msg['content']];

            } elseif ($role === 'assistant') {
                $content = [];
                if (!empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] ?? [] as $tc) {
                    $content[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'],
                        'name'  => $tc['name'],
                        'input' => $tc['input'],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $content];

            } elseif ($role === 'tool') {
                // Append as a user message with tool_result block
                $content = json_decode($msg['content'], true) ?? $msg['content'];
                $out[] = [
                    'role'    => 'user',
                    'content' => [[
                        'type'       => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'],
                        'content'    => is_string($content) ? $content : json_encode($content),
                    ]],
                ];
            }
        }
        return $out;
    }

    private function toAnthropicTools(array $tools): array
    {
        return array_map(function ($t) {
            return [
                'name'         => $t['name'],
                'description'  => $t['description'] ?? '',
                'input_schema' => $t['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            ];
        }, $tools);
    }

    private function normalize(array $data): array
    {
        $text       = '';
        $tool_calls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $tool_calls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    'input' => $block['input'],
                ];
            }
        }

        $stopReason = $data['stop_reason'] ?? 'end_turn';

        return [
            'text'        => trim($text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => $stopReason === 'tool_use' ? 'tool_use' : 'end_turn',
        ];
    }
}
