<?php

/**
 * Benchmark runner — executes a single test case against a real LLM adapter.
 *
 * Reuses Alfred's actual adapter classes (Mistral, Gemini, …) but replaces
 * alfredMCPRegistry with MockMCPRegistry so no real Jeedom calls are made.
 * Implements a simplified ReAct loop (no DB, no SSE).
 */
class BenchmarkRunner
{
    private $alfredCoreDir;
    private $defaultSystemPrompt;
    private $jeedomMcpPath;
    private $aclMode;

    public function __construct(
        string $alfredCoreDir,
        string $defaultSystemPrompt = '',
        string $jeedomMcpPath = '',
        string $aclMode = 'read_execute'
    )
    {
        $this->alfredCoreDir = rtrim($alfredCoreDir, DIRECTORY_SEPARATOR);
        $this->jeedomMcpPath = $jeedomMcpPath;
        $this->aclMode       = $aclMode;

        $this->defaultSystemPrompt = $defaultSystemPrompt !== '' ? $defaultSystemPrompt
            : "Tu es Alfred, un assistant domotique intégré à Jeedom.\n"
            . "Tu aides les occupants à contrôler leur maison : lumières, volets, scénarios, appareils connectés.\n"
            . "Tu disposes d'outils MCP pour interagir avec Jeedom.\n\n"
            . "Règles :\n"
            . "- Réponds toujours en français.\n"
            . "- Avant d'agir, vérifie les équipements disponibles via devices_list si nécessaire.\n"
            . "- Confirme brièvement chaque action effectuée.\n"
            . "- Si une demande est ambiguë, demande des précisions plutôt qu'agir au hasard.\n"
            . "- Si un équipement n'existe pas, dis-le clairement sans inventer d'ID.\n"
            . "- Ne prends jamais d'actions irréversibles ou dangereuses sans confirmation explicite.";

        $this->loadAdapterClasses();
    }

    // -------------------------------------------------------------------------

    /**
     * Run a single test case against one model configuration.
     *
     * @param array $testCase  Decoded test case JSON
     * @param array $fixture   Decoded house fixture JSON
     * @param array $model     {provider, api_key, model}
     * @return array {tool_calls, final_text, iterations, latency_ms, error}
     */
    public function run(array $testCase, array $fixture, array $model): array
    {
        $registry = new MockMCPRegistry($fixture, $testCase['mock_overrides'] ?? [], $this->jeedomMcpPath, $this->aclMode);

        try {
            $adapter = $this->makeAdapter($model);
        } catch (Exception $e) {
            return $this->errorResult('Adapter init failed: ' . $e->getMessage());
        }

        $systemPrompt = $this->buildSystemPrompt($testCase['system_prompt'] ?? null);
        $turns        = $this->splitIntoTurns($testCase['conversation']);
        $tools        = $registry->listTools();
        $maxIter      = $testCase['expected']['max_iterations'] ?? 10;

        $start         = microtime(true);
        $iterations    = 0;
        $lastText      = '';
        $error         = null;
        $messages      = [];
        $inputTokens   = 0;
        $outputTokens  = 0;

        try {
            foreach ($turns as $turnMessages) {
                foreach ($turnMessages as $msg) {
                    $messages[] = $msg;
                }

                $iterInTurn = 0;
                while ($iterInTurn < $maxIter) {
                    $response = $this->chatWithRetry($adapter, $messages, $tools, $systemPrompt);
                    $iterations++;
                    $iterInTurn++;
                    $lastText      = $response['text'] ?? '';
                    $inputTokens  += (int)($response['usage']['input_tokens']  ?? 0);
                    $outputTokens += (int)($response['usage']['output_tokens'] ?? 0);

                    $assistantMsg = ['role' => 'assistant', 'content' => $lastText];
                    if (!empty($response['tool_calls'])) {
                        $assistantMsg['tool_calls'] = $response['tool_calls'];
                    }
                    $messages[] = $assistantMsg;

                    if ($response['stop_reason'] === 'end_turn' || empty($response['tool_calls'])) {
                        break;
                    }

                    foreach ($response['tool_calls'] as $toolCall) {
                        $result     = $registry->callTool($toolCall['name'], $toolCall['input']);
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'name'         => $toolCall['name'],
                            'content'      => json_encode($result),
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'tool_calls'    => $registry->getCallLog(),
            'final_text'    => $lastText,
            'iterations'    => $iterations,
            'latency_ms'    => (int)((microtime(true) - $start) * 1000),
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'error'         => $error,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Split a conversation array into turns.
     * A new turn starts at each "user" message that immediately follows another "user" message
     * (i.e. no assistant response in between — these are pending turns to execute separately).
     */
    private function splitIntoTurns(array $conversation): array
    {
        $turns   = [];
        $current = [];

        foreach ($conversation as $msg) {
            if ($msg['role'] === 'user' && !empty($current)) {
                // Check if last message in current is already a user message (no assistant between)
                $last = end($current);
                if ($last['role'] === 'user') {
                    // Flush current turn and start a new one
                    $turns[]  = $current;
                    $current  = [];
                }
            }
            $current[] = $msg;
        }

        if (!empty($current)) {
            $turns[] = $current;
        }

        return $turns;
    }

    private function buildSystemPrompt($override): string
    {
        $base = $override ?? $this->defaultSystemPrompt;

        // Inject context block identical to alfredAgent::buildSystemPrompt()
        return $base
            . "\n\n## Context"
            . "\n- Current date and time: " . date('l, F j Y H:i')
            . "\n- Current user login: admin"
            . "\n- Current user role: admin";
    }

    private function makeAdapter(array $cfg)
    {
        $provider = $cfg['provider'];
        $apiKey   = $cfg['api_key'];
        $model    = $cfg['model'];

        switch ($provider) {
            case 'mistral':
                return new alfredLLMMistralAdapter($apiKey, $model);
            case 'gemini':
                return new alfredLLMGeminiAdapter($apiKey, $model);
            case 'anthropic':
                if (!class_exists('alfredLLMAnthropicAdapter')) {
                    throw new Exception("Anthropic adapter not found (alfredLLMAnthropicAdapter.class.php).");
                }
                return new alfredLLMAnthropicAdapter($apiKey, $model);
            case 'openai':
                if (!class_exists('alfredLLMOpenAIAdapter')) {
                    throw new Exception("OpenAI adapter not found (alfredLLMOpenAIAdapter.class.php).");
                }
                return new alfredLLMOpenAIAdapter($apiKey, $model);
            case 'ollama':
                // $apiKey is repurposed as base URL for Ollama
                $baseUrl = $apiKey !== '' ? $apiKey : 'http://localhost:11434';
                return new alfredLLMOllamaAdapter($baseUrl, $model);
            default:
                throw new Exception("Unknown provider: '{$provider}'. Supported: mistral, gemini, anthropic, openai, ollama.");
        }
    }

    private function loadAdapterClasses(): void
    {
        require_once $this->alfredCoreDir . '/alfredLLM.class.php';

        // Load all adapter files found in the core directory
        $pattern = $this->alfredCoreDir . '/alfredLLM*Adapter.class.php';
        foreach (glob($pattern) as $file) {
            require_once $file;
        }
    }

    /**
     * Wrapper around adapter->chat() with exponential back-off on HTTP 429.
     */
    private function chatWithRetry($adapter, array $messages, array $tools, string $systemPrompt): array
    {
        $maxRetries = 5;
        $delay      = 5; // seconds, doubles each retry

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $adapter->chat($messages, $tools, $systemPrompt);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $is429 = strpos($msg, '429') !== false
                    || stripos($msg, 'rate limit') !== false
                    || stripos($msg, 'too many requests') !== false;

                if (!$is429 || $attempt === $maxRetries) {
                    throw $e;
                }

                $wait = $delay * (1 << $attempt); // 5, 10, 20, 40, 80 s
                echo "      ⏳ Rate limit hit — retrying in {$wait}s (attempt " . ($attempt + 1) . "/{$maxRetries})…\n";
                sleep($wait);
            }
        }

        throw new Exception('chatWithRetry: exhausted retries (should never reach here)');
    }

    private function errorResult(string $message): array
    {
        return [
            'tool_calls'  => [],
            'final_text'  => '',
            'iterations'  => 0,
            'latency_ms'  => 0,
            'error'       => $message,
        ];
    }
}
