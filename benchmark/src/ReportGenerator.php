<?php

/**
 * Formats benchmark results for console output and markdown reports.
 */
class ReportGenerator
{
    private $verbose;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    // -------------------------------------------------------------------------
    // Console output
    // -------------------------------------------------------------------------

    public function printHeader(string $modelLabel, string $fixtureName, int $deviceCount, int $roomCount): void
    {
        echo "\n";
        echo str_repeat('=', 78) . "\n";
        echo "  Alfred LLM Benchmark\n";
        echo "  Model   : {$modelLabel}\n";
        echo "  House   : {$fixtureName} ({$deviceCount} devices, {$roomCount} rooms)\n";
        echo "  Date    : " . date('Y-m-d H:i') . "\n";
        echo str_repeat('=', 78) . "\n\n";
    }

    public function printTestResult(array $testCase, array $result, array $evaluation): void
    {
        $icon    = $evaluation['pass'] ? '✓' : '✗';
        $score   = number_format($evaluation['score'] * 100, 0) . '%';
        $latency = $result['latency_ms'] . 'ms';
        $iters   = $result['iterations'] . ' iter';
        $tokens  = $this->formatTokens($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0);

        printf(
            "  %s %-36s  %4s  %6s  %5s  %s\n",
            $icon,
            substr($testCase['id'], 0, 36),
            $score,
            $latency,
            $iters,
            $tokens
        );

        if ($this->verbose || !$evaluation['pass']) {
            foreach ($evaluation['issues'] as $issue) {
                echo "      ↳ {$issue}\n";
            }
            if ($this->verbose) {
                foreach ($result['tool_calls'] as $call) {
                    $params = json_encode($call['input'], JSON_UNESCAPED_UNICODE);
                    echo "      → {$call['name']}({$params})\n";
                    if (isset($call['result'])) {
                        $res = json_encode($call['result'], JSON_UNESCAPED_UNICODE);
                        if (strlen($res) > 140) {
                            $res = substr($res, 0, 137) . '…';
                        }
                        echo "        ← {$res}\n";
                    }
                }
                if ($result['final_text'] !== '') {
                    $text = substr($result['final_text'], 0, 120);
                    echo "      ✉ " . str_replace("\n", ' ', $text) . "\n";
                }
            }
        }
    }

    public function printCategorySummary(string $category, array $categoryResults): void
    {
        $total   = count($categoryResults);
        $passed  = count(array_filter($categoryResults, function ($r) { return $r['evaluation']['pass']; }));
        $results = array_column($categoryResults, 'result');
        $avgMs   = $total > 0 ? (int)(array_sum(array_column($results, 'latency_ms')) / $total) : 0;
        $pct     = $total > 0 ? round($passed / $total * 100) : 0;
        $inTok   = array_sum(array_column($results, 'input_tokens'));
        $outTok  = array_sum(array_column($results, 'output_tokens'));
        $tokens  = $this->formatTokens($inTok, $outTok);

        printf(
            "  %-28s  %2d/%2d (%3d%%)  avg %dms  %s\n",
            $category, $passed, $total, $pct, $avgMs, $tokens
        );
    }

    public function printGrandTotal(array $allResults): void
    {
        $total   = count($allResults);
        $passed  = count(array_filter($allResults, function ($r) { return $r['evaluation']['pass']; }));
        $results = array_column($allResults, 'result');
        $avgMs   = $total > 0 ? (int)(array_sum(array_column($results, 'latency_ms')) / $total) : 0;
        $pct     = $total > 0 ? round($passed / $total * 100) : 0;
        $inTok   = array_sum(array_column($results, 'input_tokens'));
        $outTok  = array_sum(array_column($results, 'output_tokens'));

        echo "\n" . str_repeat('-', 78) . "\n";
        printf(
            "  TOTAL %-22s  %2d/%2d (%3d%%)  avg %dms\n",
            '', $passed, $total, $pct, $avgMs
        );
        printf(
            "  Tokens total: %s input + %s output = %s\n",
            number_format($inTok),
            number_format($outTok),
            number_format($inTok + $outTok)
        );
        echo str_repeat('=', 78) . "\n\n";
    }

    // -------------------------------------------------------------------------
    // Markdown report
    // -------------------------------------------------------------------------

    public function generateMarkdown(
        string $modelLabel,
        string $fixtureName,
        array  $allResults
    ): string {
        $total  = count($allResults);
        $passed = count(array_filter($allResults, function ($r) { return $r['evaluation']['pass']; }));
        $pct    = $total > 0 ? round($passed / $total * 100) : 0;
        $avgMs  = $total > 0
            ? (int)array_sum(array_column(array_column($allResults, 'result'), 'latency_ms')) / $total
            : 0;

        $results  = array_column($allResults, 'result');
        $inTok    = array_sum(array_column($results, 'input_tokens'));
        $outTok   = array_sum(array_column($results, 'output_tokens'));

        $md  = "# Alfred LLM Benchmark\n\n";
        $md .= "**Model:** {$modelLabel}  \n";
        $md .= "**House:** {$fixtureName}  \n";
        $md .= "**Date:** " . date('Y-m-d H:i') . "  \n";
        $md .= "**Score:** {$passed}/{$total} ({$pct}%) — avg latency {$avgMs}ms  \n";
        $md .= "**Tokens:** " . number_format($inTok) . " input + " . number_format($outTok) . " output = **" . number_format($inTok + $outTok) . " total**\n\n";

        // Per-category table
        $byCategory = [];
        foreach ($allResults as $r) {
            $byCategory[$r['category']][] = $r;
        }

        $md .= "## Summary by category\n\n";
        $md .= "| Category | Pass | Total | Score | Avg latency | Tokens (in+out) |\n";
        $md .= "|----------|------|-------|-------|-------------|----------------|\n";

        foreach ($byCategory as $cat => $rows) {
            $n      = count($rows);
            $p      = count(array_filter($rows, function ($r) { return $r['evaluation']['pass']; }));
            $pct    = $n > 0 ? round($p / $n * 100) : 0;
            $rr     = array_column($rows, 'result');
            $avg    = $n > 0 ? (int)(array_sum(array_column($rr, 'latency_ms')) / $n) : 0;
            $inTk   = array_sum(array_column($rr, 'input_tokens'));
            $outTk  = array_sum(array_column($rr, 'output_tokens'));
            $tokStr = ($inTk + $outTk > 0) ? number_format($inTk) . '+' . number_format($outTk) : '—';
            $md .= "| {$cat} | {$p} | {$n} | {$pct}% | {$avg}ms | {$tokStr} |\n";
        }

        // Detailed results
        $md .= "\n## Test details\n\n";
        foreach ($allResults as $r) {
            $icon  = $r['evaluation']['pass'] ? '✅' : '❌';
            $score = number_format($r['evaluation']['score'] * 100, 0);
            $md   .= "### {$icon} `{$r['id']}` ({$score}%)\n\n";
            $md   .= "**Category:** {$r['category']}  \n";
            $md   .= "**Description:** {$r['testCase']['description']}  \n";
            $inTk  = (int)($r['result']['input_tokens']  ?? 0);
            $outTk = (int)($r['result']['output_tokens'] ?? 0);
            $tokStr = ($inTk + $outTk > 0) ? number_format($inTk) . ' in + ' . number_format($outTk) . ' out' : '—';
            $md   .= "**Latency:** {$r['result']['latency_ms']}ms — **Iterations:** {$r['result']['iterations']} — **Tokens:** {$tokStr}  \n\n";

            if (!empty($r['evaluation']['issues'])) {
                $md .= "**Issues:**\n";
                foreach ($r['evaluation']['issues'] as $issue) {
                    $md .= "- {$issue}\n";
                }
                $md .= "\n";
            }

            if (!empty($r['result']['tool_calls'])) {
                $md .= "<details><summary>Tool calls (" . count($r['result']['tool_calls']) . ")</summary>\n\n";
                foreach ($r['result']['tool_calls'] as $call) {
                    $inputJson  = json_encode($call['input'],  JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $resultJson = isset($call['result'])
                        ? $this->abbreviateResult($call['result'])
                        : 'null';
                    $md .= "**`→ {$call['name']}`**\n\n";
                    $md .= "```json\n{$inputJson}\n```\n\n";
                    $md .= "**`← result`**\n\n";
                    $md .= "```json\n{$resultJson}\n```\n\n";
                }
                $md .= "</details>\n\n";
            }

            if ($r['result']['final_text'] !== '') {
                $md .= "<details><summary>Final response</summary>\n\n> "
                    . str_replace("\n", "\n> ", htmlspecialchars($r['result']['final_text']))
                    . "\n\n</details>\n\n";
            }
        }

        return $md;
    }

    // -------------------------------------------------------------------------

    private function formatTokens(int $in, int $out): string
    {
        if ($in === 0 && $out === 0) return '—';
        return number_format($in) . '+' . number_format($out);
    }

    /**
     * Abbreviate large tool results for markdown: keep action results whole,
     * truncate items arrays to first 3 entries with a count summary.
     */
    private function abbreviateResult(array $result): string
    {
        if (isset($result['items']) && is_array($result['items'])) {
            $total  = $result['total'] ?? count($result['items']);
            $sample = array_slice($result['items'], 0, 3);
            $abbreviated = $result;
            $abbreviated['items'] = $sample;
            if (count($result['items']) > 3) {
                $abbreviated['_note'] = "… {$total} items total, showing first 3";
            }
            return json_encode($abbreviated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 800) {
            $json = substr($json, 0, 797) . "\n… (truncated)";
        }
        return $json;
    }
}
