<?php

class alfredLLMOpenAIAdapter extends alfredLLMAdapter
{
    private const API_URL    = 'https://api.openai.com/v1/chat/completions';
    private const MODELS_URL = 'https://api.openai.com/v1/models';

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $oaiMessages = [];
        if ($systemPrompt !== '') {
            $oaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($this->toOpenAIMessages($messages) as $m) {
            $oaiMessages[] = $m;
        }

        $body = [
            'model'    => $this->model,
            'messages' => $oaiMessages,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->toOpenAITools($tools);
        }

        $data = $this->httpPost(self::API_URL, $this->headers(), $body);

        return $this->normalize($data);
    }

    public function chatStream(array $messages, array $tools, string $systemPrompt, callable $onDelta): array
    {
        $oaiMessages = [];
        if ($systemPrompt !== '') {
            $oaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($this->toOpenAIMessages($messages) as $m) {
            $oaiMessages[] = $m;
        }

        $body = [
            'model'    => $this->model,
            'messages' => $oaiMessages,
            'stream'   => true,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->toOpenAITools($tools);
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

                    // Accumulate error messages from non-2xx responses
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

                    // Text chunk
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $chunk  = $delta['content'];
                        $text  .= $chunk;
                        $onDelta($chunk);
                    }

                    // Tool call accumulation (index-based, streamed in parts)
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
            $msg = $error_body !== '' ? $error_body : "HTTP {$code}";
            throw new Exception("OpenAI API error: {$msg}");
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
        return ['ok' => true, 'provider' => 'openai', 'model' => $this->model];
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
            throw new Exception('OpenAI models API error (HTTP ' . $code . ')');
        }

        $models = [];
        foreach ($data['data'] as $m) {
            $id = $m['id'] ?? '';
            // Keep only chat-capable models
            if (!preg_match('/^(gpt-|o1|o3)/', $id)) continue;
            $models[] = ['id' => $id, 'name' => $id];
        }
        usort($models, fn($a, $b) => strcmp($b['id'], $a['id']));
        return $models;
    }

    // -------------------------------------------------------------------------

    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
    }

    private function toOpenAIMessages(array $messages): array
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
                ];
            }
        }
        return $out;
    }

    private function toOpenAITools(array $tools): array
    {
        return array_map(function ($t) {
            return [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters'  => $t['inputSchema'] ?? ['type' => 'object', 'properties' => []],
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
            $input = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $tool_calls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['function']['name'],
                'input' => $input,
            ];
        }

        $finishReason = $choice['finish_reason'] ?? 'stop';

        return [
            'text'        => trim((string)$text),
            'tool_calls'  => $tool_calls,
            'stop_reason' => $finishReason === 'tool_calls' ? 'tool_use' : 'end_turn',
        ];
    }
}
