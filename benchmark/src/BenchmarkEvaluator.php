<?php

/**
 * Evaluates the output of a benchmark run against the test case expectations.
 *
 * Scoring rules:
 *   - Each required_tool_call that is satisfied counts as a passing check.
 *   - Each forbidden_tool_call that is NOT triggered counts as a passing check.
 *   - no_hallucinated_ids: all command_execute / scenario_run calls must use
 *     IDs that exist in the fixture.
 *   - must_not_execute: no command_execute or scenario_run call at all.
 *   - score = passing_checks / total_checks  (1.0 = full pass)
 */
class BenchmarkEvaluator
{
    /**
     * @param array $testCase  The test case (has 'expected' key)
     * @param array $result    Output from BenchmarkRunner::run()
     * @param array $fixture   The house fixture (for ID validation)
     * @return array {score, pass, checks, issues}
     */
    public function evaluate(array $testCase, array $result, array $fixture): array
    {
        $expected   = $testCase['expected'] ?? [];
        $callLog    = $result['tool_calls'] ?? [];
        $checks     = [];
        $issues     = [];

        if ($result['error'] !== null) {
            return [
                'score'  => 0.0,
                'pass'   => false,
                'checks' => [],
                'issues' => ['Runner error: ' . $result['error']],
            ];
        }

        // --- 1. required_tool_calls ----------------------------------------

        foreach ($expected['required_tool_calls'] ?? [] as $req) {
            $match  = $this->findMatchingCall($req, $callLog);
            $pass   = $match !== null;
            $detail = $pass
                ? "Found: " . $req['name'] . '(' . json_encode($req['params'] ?? []) . ')'
                : "Missing: " . $req['name'] . '(' . json_encode($req['params'] ?? []) . ')';

            $checks[] = ['type' => 'required', 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $issues[] = $detail;
            }
        }

        // --- 2. forbidden_tool_calls ----------------------------------------

        foreach ($expected['forbidden_tool_calls'] ?? [] as $forb) {
            $match  = $this->findMatchingCall($forb, $callLog);
            $pass   = $match === null; // forbidden = must NOT be found
            $detail = $pass
                ? "Correctly absent: " . $forb['name']
                : "Forbidden call made: " . $forb['name'] . '(' . json_encode($forb['params'] ?? []) . ')';

            $checks[] = ['type' => 'forbidden', 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $issues[] = $detail;
            }
        }

        // --- 3. must_not_execute -------------------------------------------

        if (!empty($expected['must_not_execute'])) {
            $executed = false;
            foreach ($callLog as $call) {
                if (in_array($call['name'], ['command_execute', 'scenario_run'], true)) {
                    $executed = true;
                    break;
                }
            }
            $pass     = !$executed;
            $detail   = $pass ? 'No action executed (correct)' : 'Action executed when it should not have been';
            $checks[] = ['type' => 'must_not_execute', 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $issues[] = $detail;
            }
        }

        // --- 4. no_guessed_room_ids ----------------------------------------
        // Penalise devices_list calls that pass room_ids not present in the fixture.
        // A well-behaved model first calls rooms_list to discover IDs.

        if (!empty($expected['no_guessed_room_ids'])) {
            // A model must call rooms_list before using room_ids in devices_list.
            // Checking only for non-existent IDs misses lucky guesses of valid IDs.
            $violations = [];
            $roomsDiscovered = false;

            foreach ($callLog as $call) {
                if ($call['name'] === 'rooms_list') {
                    $roomsDiscovered = true;
                }
                if ($call['name'] === 'devices_list' && !empty($call['input']['room_ids'])) {
                    if (!$roomsDiscovered) {
                        $ids = implode(', ', (array)$call['input']['room_ids']);
                        $violations[] = "room_ids=[{$ids}] used before rooms_list was called";
                    }
                }
            }

            $pass     = empty($violations);
            $detail   = $pass
                ? 'Room IDs properly discovered via rooms_list'
                : implode('; ', $violations);
            $checks[] = ['type' => 'no_guessed_room_ids', 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $issues[] = $detail;
            }
        }

        // --- 5. no_hallucinated_ids ----------------------------------------

        if (!empty($expected['no_hallucinated_ids'])) {
            $validCmdIds      = $this->collectCommandIds($fixture);
            $validScenarioIds = $this->collectScenarioIds($fixture);
            $hallucinations   = [];

            foreach ($callLog as $call) {
                if ($call['name'] === 'command_execute') {
                    // Real API format: {commands: [{id, value} | {ids, value}]}
                    foreach ($call['input']['commands'] ?? [] as $cmd) {
                        $ids = isset($cmd['ids'])
                            ? array_map('intval', (array)$cmd['ids'])
                            : [(int)($cmd['id'] ?? 0)];
                        foreach ($ids as $id) {
                            if ($id !== 0 && !in_array($id, $validCmdIds, true)) {
                                $hallucinations[] = "command_execute(id={$id}) — ID not in fixture";
                            }
                        }
                    }
                }
                if ($call['name'] === 'scenario_run') {
                    $id = (int)($call['input']['scenario_id'] ?? 0);
                    if (!in_array($id, $validScenarioIds, true)) {
                        $hallucinations[] = "scenario_run(scenario_id={$id}) — ID not in fixture";
                    }
                }
            }

            $pass     = empty($hallucinations);
            $detail   = $pass ? 'All IDs valid' : 'Hallucinated IDs: ' . implode(', ', $hallucinations);
            $checks[] = ['type' => 'no_hallucinated_ids', 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $issues = array_merge($issues, $hallucinations);
            }
        }

        // --- Score ----------------------------------------------------------

        $total  = count($checks);
        $passed = count(array_filter($checks, function ($c) { return $c['pass']; }));
        $score  = $total > 0 ? round($passed / $total, 3) : 1.0;
        $pass   = $score >= 1.0;

        return compact('score', 'pass', 'checks', 'issues');
    }

    // -------------------------------------------------------------------------

    /**
     * Find a call in the log that satisfies a required/forbidden entry.
     * match modes: contains (default), exact, any
     */
    private function findMatchingCall(array $entry, array $callLog): ?array
    {
        $name       = $entry['name'];
        $params     = $entry['params'] ?? [];
        $matchMode  = $entry['match']  ?? 'contains';

        foreach ($callLog as $call) {
            if ($call['name'] !== $name) {
                continue;
            }
            if ($matchMode === 'any') {
                return $call;
            }
            if ($matchMode === 'exact') {
                // All specified params match and no extra params in the call
                $callParams = $call['input'] ?? [];
                if ($callParams == $params) {
                    return $call;
                }
            }
            if ($matchMode === 'contains') {
                // All specified params present and equal in the call
                if ($this->paramsMatch($params, $call['input'] ?? [])) {
                    return $call;
                }
            }
        }

        return null;
    }

    /**
     * True if all keys in $expected exist and match in $actual.
     * Supports nested arrays and a special {"min":X,"max":Y} range object.
     */
    private function paramsMatch(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $expectedValue) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            $actualValue = $actual[$key];

            // Range check: {"min": X, "max": Y}
            if (is_array($expectedValue)
                && isset($expectedValue['min'])
                && isset($expectedValue['max'])
            ) {
                $num = (float)$actualValue;
                if ($num < $expectedValue['min'] || $num > $expectedValue['max']) {
                    return false;
                }
                continue;
            }

            // Array-of-objects containment: every expected item must match at least one actual item.
            // Used for command_execute {commands: [{id: 803}]} — checks that the commands array
            // contains an entry with id=803, regardless of other entries.
            if (is_array($expectedValue)
                && !empty($expectedValue)
                && is_array($expectedValue[0])
            ) {
                if (!is_array($actualValue)) {
                    return false;
                }
                foreach ($expectedValue as $expectedItem) {
                    $found = false;
                    foreach ($actualValue as $actualItem) {
                        if (is_array($actualItem) && $this->paramsMatch($expectedItem, $actualItem)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
                continue;
            }

            // id vs ids equivalence: {id:804} matches {ids:[804,...]} and vice-versa
            if ($key === 'id' && !array_key_exists('id', $actual) && array_key_exists('ids', $actual)) {
                if (!in_array((int)$expectedValue, array_map('intval', (array)$actual['ids']), true)) {
                    return false;
                }
                continue;
            }

            // Type-coerced scalar comparison (int vs string IDs)
            if ((string)$actualValue !== (string)$expectedValue) {
                return false;
            }
        }
        return true;
    }

    private function collectCommandIds(array $fixture): array
    {
        $ids = [];
        foreach ($fixture['devices'] ?? [] as $device) {
            foreach ($device['actions'] ?? [] as $action) {
                $ids[] = (int)$action['id'];
            }
        }
        return $ids;
    }

    private function collectRoomIds(array $fixture): array
    {
        return array_map(function ($r) { return (int)$r['id']; }, $fixture['rooms'] ?? []);
    }

    private function collectScenarioIds(array $fixture): array
    {
        return array_map(function ($s) { return (int)$s['id']; }, $fixture['scenarios'] ?? []);
    }
}
