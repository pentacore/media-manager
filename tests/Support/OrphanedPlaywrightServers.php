<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Stops Playwright servers whose test run is gone.
 *
 * pest-plugin-browser starts `playwright run-server` as a child of the PHP
 * test process and stops it when Pest terminates. A run that is killed
 * (timeout, interrupted command, OOM) or whose browser tests time out never
 * gets there, so its server is re-parented to PID 1 and runs forever. A
 * server that belongs to a live run is always a child of that run's PHP
 * process, so only the PID 1 children are safe to stop.
 */
final class OrphanedPlaywrightServers
{
    public const string PATTERN = 'playwright run-server';

    /**
     * Stop every orphaned process whose command line matches the pattern; a
     * no-op off Linux or where pkill is missing.
     */
    public static function reap(string $pattern = self::PATTERN): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }

        new Process(['pkill', '-KILL', '-P', '1', '-f', $pattern])->run();
    }
}
