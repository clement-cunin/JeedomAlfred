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
