<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/**
 * Alfred LLM Benchmark — CLI entry point
 *
 * Usage:
 *   php benchmark/run.php --provider=mistral --model=mistral-small-latest
 *   php benchmark/run.php --provider=gemini  --model=gemini-2.0-flash --house=studio --verbose
 *   php benchmark/run.php --provider=mistral --model=mistral-large-latest --test=impossible --output=reports/run.md
 *
 * Options:
 *   --provider=<name>   LLM provider: mistral, gemini, anthropic, openai
 *   --model=<name>      Model identifier (e.g. mistral-small-latest)
 *   --house=<name>      Fixture: reference, studio, large  (default: reference)
 *   --test=<name>       Test category or "all"            (default: all)
 *   --output=<file>     Save markdown report to this file
 *   --verbose           Show detailed per-test output
 *   --list-models       List available models for the provider and exit
 *
 * Config file: benchmark/config.php (copy from config.example.php)
 */

define('BENCHMARK_DIR', __DIR__);
define('ALFRED_CORE',   __DIR__ . '/../core/class');

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/src/MockMCPRegistry.php';
require_once __DIR__ . '/src/BenchmarkRunner.php';
require_once __DIR__ . '/src/BenchmarkEvaluator.php';
require_once __DIR__ . '/src/ReportGenerator.php';

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("Error: config.php not found. Copy config.example.php to config.php and fill in your API keys.\n");
}
$config = require $configFile;

// ── Parse arguments ───────────────────────────────────────────────────────────

$opts = getopt('', [
    'provider:', 'model:', 'house:', 'test:', 'output:', 'verbose', 'list-models', 'delay:',
]);

$provider   = $opts['provider'] ?? null;
$model      = $opts['model']    ?? null;
$houseName  = $opts['house']    ?? 'reference';
$testFilter = $opts['test']     ?? 'all';
$outputFile = $opts['output'] ?? null;
$verbose    = isset($opts['verbose']);
$listModels = isset($opts['list-models']);
$delaySec   = (int)($opts['delay'] ?? 0); // --delay=2 => 2s pause between tests

if ($provider === null || $model === null) {
    die("Usage: php run.php --provider=<name> --model=<name> [--house=reference|studio|large] [--test=all|<category>] [--output=file.md] [--verbose]\n");
}

$apiKey = $config['providers'][$provider]['api_key'] ?? '';
if ($apiKey === '') {
    die("Error: No API key configured for provider '{$provider}' in config.php.\n");
}

$modelConfig = ['provider' => $provider, 'api_key' => $apiKey, 'model' => $model];

if ($outputFile === null) {
    $safeModel  = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $model);
    $outputFile = __DIR__ . "/reports/{$testFilter}_{$safeModel}.md";
}

// ── List models (optional) ────────────────────────────────────────────────────

if ($listModels) {
    $runner = new BenchmarkRunner(ALFRED_CORE);
    // makeAdapter is private, so we instantiate a dummy run to trigger loading, then use reflection
    // Simpler: just instantiate directly
    require_once ALFRED_CORE . '/alfredLLM.class.php';
    foreach (glob(ALFRED_CORE . '/alfredLLM*Adapter.class.php') as $f) { require_once $f; }

    $adapterClass = 'alfredLLM' . ucfirst($provider) . 'Adapter';
    if (!class_exists($adapterClass)) {
        die("Adapter class {$adapterClass} not found.\n");
    }
    $adapter = new $adapterClass($apiKey, 'placeholder');
    try {
        $models = $adapter->listModels();
        echo "Available models for {$provider}:\n";
        foreach ($models as $m) {
            echo "  - " . ($m['id'] ?? $m['name'] ?? json_encode($m)) . "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(0);
}

// ── Load fixture ──────────────────────────────────────────────────────────────

$fixtureFile = __DIR__ . "/fixtures/house_{$houseName}.json";
if (!file_exists($fixtureFile)) {
    die("Error: Fixture file not found: {$fixtureFile}\n");
}
$fixture = json_decode(file_get_contents($fixtureFile), true);
if (!$fixture) {
    die("Error: Invalid JSON in fixture: {$fixtureFile}\n");
}

// ── Load test cases ───────────────────────────────────────────────────────────

$testFiles = glob(__DIR__ . '/tests/*.json');
sort($testFiles);
$allTestCases = [];

foreach ($testFiles as $file) {
    $cases = json_decode(file_get_contents($file), true);
    if (!is_array($cases)) {
        echo "Warning: Invalid JSON in {$file}, skipping.\n";
        continue;
    }
    foreach ($cases as $case) {
        // Determine fixture to use: test-level override or default to CLI --house
        $caseFixtureName = $case['fixture'] ?? $houseName;
        if ($caseFixtureName !== $houseName) {
            // Load the case-specific fixture
            $cf = __DIR__ . "/fixtures/house_{$caseFixtureName}.json";
            $caseFixture = file_exists($cf) ? json_decode(file_get_contents($cf), true) : $fixture;
        } else {
            $caseFixture = $fixture;
        }
        $allTestCases[] = ['case' => $case, 'fixture' => $caseFixture];
    }
}

if ($testFilter !== 'all') {
    $allTestCases = array_values(array_filter($allTestCases, function ($t) use ($testFilter) {
        return ($t['case']['category'] ?? '') === $testFilter;
    }));
}

if (empty($allTestCases)) {
    die("No test cases found" . ($testFilter !== 'all' ? " for category '{$testFilter}'" : '') . ".\n");
}

// ── Run benchmark ─────────────────────────────────────────────────────────────

$runner    = new BenchmarkRunner(ALFRED_CORE, $config['system_prompt'] ?? '', $config['jeedom_mcp_path'] ?? '', $config['acl_mode'] ?? 'read_execute');
$evaluator = new BenchmarkEvaluator();
$report    = new ReportGenerator($verbose);

$deviceCount = count($fixture['devices'] ?? []);
$roomCount   = count($fixture['rooms']   ?? []);
$modelLabel  = "{$provider}:{$model}";

$report->printHeader($modelLabel, $houseName, $deviceCount, $roomCount);
echo str_repeat('-', 78) . "\n";
printf("  %s %-36s  %4s  %6s  %5s  %s\n", ' ', 'Test', 'Score', 'Latency', 'Iters', 'Tokens (in+out)');
echo str_repeat('-', 78) . "\n";

$allResults = [];
$byCategory = [];

foreach ($allTestCases as $entry) {
    $testCase = $entry['case'];
    $fix      = $entry['fixture'];
    $category = $testCase['category'] ?? 'uncategorized';

    if ($delaySec > 0) {
        sleep($delaySec);
    }

    $result     = $runner->run($testCase, $fix, $modelConfig);
    $evaluation = $evaluator->evaluate($testCase, $result, $fix);

    $row = [
        'id'         => $testCase['id'],
        'category'   => $category,
        'testCase'   => $testCase,
        'result'     => $result,
        'evaluation' => $evaluation,
    ];

    $allResults[]          = $row;
    $byCategory[$category][] = $row;

    $report->printTestResult($testCase, $result, $evaluation);
}

echo str_repeat('-', 78) . "\n";
echo "\n  By category:\n";
foreach ($byCategory as $cat => $rows) {
    $report->printCategorySummary($cat, $rows);
}
$report->printGrandTotal($allResults);

// ── Save markdown report ──────────────────────────────────────────────────────

if ($outputFile !== null) {
    $dir = dirname($outputFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $md = $report->generateMarkdown($modelLabel, $houseName, $allResults);
    file_put_contents($outputFile, $md);
    echo "Report saved to: {$outputFile}\n\n";
}
