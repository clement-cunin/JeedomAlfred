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
