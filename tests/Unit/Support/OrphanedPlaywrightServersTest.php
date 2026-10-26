<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\OrphanedPlaywrightServers;

/**
 * The pid of the process whose command line contains the marker, or null.
 */
function orphanedPlaywrightPid(string $marker): ?int
{
    $process = new Process(['pgrep', '-f', $marker]);
    $process->run();

    $pid = (int) strtok($process->getOutput(), "\n");

    return $pid > 0 ? $pid : null;
}

function orphanedPlaywrightParent(int $pid): ?int
{
    $status = @file_get_contents(sprintf('/proc/%d/status', $pid));

    return is_string($status) && preg_match('/^PPid:\s+(\d+)/m', $status, $matches) === 1 ? (int) $matches[1] : null;
}

function orphanedPlaywrightCommand(string $marker): string
{
    return sprintf('exec -a "node ./node_modules/.bin/playwright run-server --mode launchServer %s" sleep 300', $marker);
}

test('only Playwright servers whose test run is gone are stopped', function (): void {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Process parents are read from /proc.');
    }

    // A run-specific marker keeps the test away from real orphaned servers.
    $run = 'reap-test-'.bin2hex(random_bytes(6));
    $orphanMarker = $run.'-orphan';
    $liveMarker = $run.'-live';

    // The subshell exits straight away, so its background child is re-parented.
    new Process(['bash', '-c', sprintf('(%s &)', orphanedPlaywrightCommand($orphanMarker))])->run();
    $live = new Process(['bash', '-c', orphanedPlaywrightCommand($liveMarker)]);
    $live->start();

    try {
        Sleep::usleep(200_000);
        $orphan = orphanedPlaywrightPid($orphanMarker);
        $livePid = orphanedPlaywrightPid($liveMarker);

        expect($orphan)->not->toBeNull()
            ->and($livePid)->not->toBeNull();

        if (orphanedPlaywrightParent($orphan) !== 1) {
            $this->markTestSkipped('Orphans here are adopted by a subreaper, not PID 1.');
        }

        OrphanedPlaywrightServers::reap(sprintf('%s.*%s', OrphanedPlaywrightServers::PATTERN, $run));
        Sleep::usleep(200_000);

        expect(orphanedPlaywrightPid($orphanMarker))->toBeNull()
            ->and(orphanedPlaywrightPid($liveMarker))->toBe($livePid)
            ->and($live->isRunning())->toBeTrue();
    } finally {
        $live->stop(0);
        new Process(['pkill', '-KILL', '-f', $orphanMarker])->run();
    }
});
