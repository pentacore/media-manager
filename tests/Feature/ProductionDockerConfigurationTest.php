<?php

declare(strict_types=1);

test('production queue worker timeout is lower than retry after defaults', function (): void {
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));

    preg_match('/\s+queue\)(.*?)\s+;;/s', (string) $entrypoint, $queueBlock);
    expect($queueBlock)->not->toBeEmpty();

    preg_match('/--timeout=(\d+)/', $queueBlock[1], $timeout);
    expect($timeout)->not->toBeEmpty();

    $workerTimeout = (int) $timeout[1];

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($workerTimeout);
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($workerTimeout);
    expect(config('queue.connections.beanstalkd.retry_after'))->toBeGreaterThan($workerTimeout);
});

test('production reverb healthcheck accepts http status codes without curl fail-fast', function (): void {
    $healthcheck = file_get_contents(base_path('docker/production/healthcheck.sh'));

    preg_match('/\s+reverb\)(.*?)\s+;;/s', (string) $healthcheck, $reverbBlock);
    expect($reverbBlock)->not->toBeEmpty();

    expect((bool) preg_match('/curl\s+-[^\s]*f/', $reverbBlock[1]))->toBeFalse();
    expect($reverbBlock[1])->toContain("grep -qE '^[234]'");
});

test('production migrate role uses isolated migrations', function (): void {
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));

    preg_match('/\s+migrate\)(.*?)\s+;;/s', (string) $entrypoint, $migrateBlock);
    expect($migrateBlock)->not->toBeEmpty();

    expect($migrateBlock[1])->toContain('php artisan migrate --force --isolated');
});
