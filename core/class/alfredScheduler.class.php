<?php

require_once __DIR__ . '/alfredAsyncTask.class.php';

/**
 * Backward-compatibility facade for alfredAsyncTask.
 *
 * Third-party plugins that call alfredScheduler::schedule() continue to work
 * without modification. New code should use alfredAsyncTask directly.
 *
 * @deprecated Use alfredAsyncTask instead.
 */
class alfredScheduler
{
    /**
     * @api Stable — delegates to alfredAsyncTask::schedule().
     */
    public static function schedule(string $sessionId, int $delaySeconds, string $instruction): array
    {
        return alfredAsyncTask::schedule($sessionId, $delaySeconds, $instruction);
    }

    public static function processPending(): void
    {
        alfredAsyncTask::processPending();
    }
}
