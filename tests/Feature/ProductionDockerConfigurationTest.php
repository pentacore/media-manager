<?php

declare(strict_types=1);

use App\Enums\QueueLane;
use App\Settings\AiSettings;

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

test('production queue container stop grace period covers the worker timeout', function (): void {
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));
    $compose = file_get_contents(base_path('docker/production/compose.yaml'));

    preg_match('/\s+queue\)(.*?)\s+;;/s', (string) $entrypoint, $queueBlock);
    preg_match('/--timeout=(\d+)/', $queueBlock[1] ?? '', $timeout);
    expect($timeout)->not->toBeEmpty();

    preg_match('/\n  queue:\n(.*?)(?=\n  [a-z][a-z-]+:|\nvolumes:)/s', (string) $compose, $queueServiceBlock);
    expect($queueServiceBlock)->not->toBeEmpty();

    preg_match('/stop_grace_period:\s*(\d+)s/', $queueServiceBlock[1], $gracePeriod);
    expect($gracePeriod)->not->toBeEmpty()
        ->and((int) $gracePeriod[1])->toBeGreaterThan((int) $timeout[1]);
});

test('production reverb healthcheck accepts http status codes without curl fail-fast', function (): void {
    $healthcheck = file_get_contents(base_path('docker/production/healthcheck.sh'));

    preg_match('/\s+reverb\)(.*?)\s+;;/s', (string) $healthcheck, $reverbBlock);
    expect($reverbBlock)->not->toBeEmpty();

    expect((bool) preg_match('/curl\s+-[^\s]*f/', $reverbBlock[1]))->toBeFalse();
    expect($reverbBlock[1])->toContain("grep -qE '^[234]'");
});

test('production migrate role delegates to run_migrations', function (): void {
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));

    preg_match('/\s+migrate\)(.*?)\s+;;/s', (string) $entrypoint, $migrateBlock);
    expect($migrateBlock)->not->toBeEmpty();

    expect($migrateBlock[1])->toContain('run_migrations');
});

test('production template trusts no proxy by default', function (): void {
    $environment = (string) file_get_contents(base_path('docker/production/.env.example'));

    preg_match('/^TRUSTED_PROXIES=(.*)$/m', $environment, $trustedProxies);

    expect($trustedProxies)->not->toBeEmpty()
        ->and(trim($trustedProxies[1]))->toBe('');
});

test('production Octane ceiling stays above the AI chat timeout plus its margin', function (): void {
    $environment = (string) file_get_contents(base_path('docker/production/.env.example'));
    $octaneConfig = (string) file_get_contents(base_path('config/octane.php'));
    $phpIni = (string) file_get_contents(base_path('docker/production/php.ini'));

    preg_match('/^OCTANE_MAX_EXECUTION_TIME=(\d+)$/m', $environment, $octane);
    preg_match('/^MEDIAMANAGER_AI_CHAT_TIMEOUT=(\d+)$/m', $environment, $chat);
    preg_match('/^max_execution_time = (\d+)$/m', $phpIni, $iniLimit);

    expect($octane)->not->toBeEmpty()
        ->and($chat)->not->toBeEmpty()
        ->and((int) $octane[1])->toBeGreaterThanOrEqual((int) $chat[1] + AiSettings::OCTANE_TIMEOUT_MARGIN_SECONDS)
        ->and($octaneConfig)->toContain(sprintf("env('OCTANE_MAX_EXECUTION_TIME', %d)", (int) $octane[1]))
        ->and((int) ($iniLimit[1] ?? 0))->toBe((int) $octane[1]);
});

test('production documentation explains trusting only the reverse proxy', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($readme)->toContain('### Reverse proxy and trusted proxies')
        ->and($readme)->toContain('TRUSTED_PROXIES=192.168.1.10');
});

test('production frontend build creates an Inertia SSR bundle', function (): void {
    $package = json_decode(
        (string) file_get_contents(base_path('package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $viteConfig = file_get_contents(base_path('vite.config.ts'));

    expect($package['scripts']['build'])->toBe('vite build && vite build --ssr')
        ->and(base_path('resources/js/ssr.ts'))->toBeFile()
        ->and($viteConfig)->toContain("entry: 'resources/js/ssr.ts'")
        ->and($viteConfig)->toContain("host: '0.0.0.0'")
        ->and($viteConfig)->toContain('port: 13714');
});

test('production stack runs SSR as an internal graceful-degradation service', function (): void {
    $dockerfile = file_get_contents(base_path('docker/production/Dockerfile'));
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));
    $healthcheck = file_get_contents(base_path('docker/production/healthcheck.sh'));
    $compose = file_get_contents(base_path('docker/production/compose.yaml'));
    $environment = file_get_contents(base_path('docker/production/.env.example'));

    preg_match('/\s+ssr\)(.*?)\s+;;/s', (string) $entrypoint, $ssrEntrypointBlock);
    preg_match('/\s+ssr\)(.*?)\s+;;/s', (string) $healthcheck, $ssrHealthcheckBlock);
    preg_match('/\n  ssr:\n(.*?)(?=\n  [a-z][a-z-]+:|\nvolumes:)/s', (string) $compose, $ssrServiceBlock);
    preg_match('/\n  web:\n(.*?)(?=\n  [a-z][a-z-]+:|\nvolumes:)/s', (string) $compose, $webServiceBlock);

    $ssrEntrypoint = $ssrEntrypointBlock[1] ?? '';
    $ssrHealthcheck = $ssrHealthcheckBlock[1] ?? '';
    $ssrService = $ssrServiceBlock[1] ?? '';
    $webService = $webServiceBlock[1] ?? '';

    expect($dockerfile)->toMatch('/FROM dunglas\/frankenphp:.*?RUN apk add --no-cache\s+.*?nodejs/s')
        ->and($ssrEntrypoint)->toContain('php artisan inertia:start-ssr')
        ->and($ssrHealthcheck)->toContain('php artisan inertia:check-ssr')
        ->and($ssrService)->toContain('CONTAINER_ROLE: ssr')
        ->and($ssrService)->not->toContain('ports:')
        ->and($webService)->not->toBeEmpty()
        ->and($webService)->not->toContain('ssr:')
        ->and($environment)->toContain('INERTIA_SSR_ENABLED=true')
        ->and($environment)->toContain('INERTIA_SSR_URL=http://ssr:13714')
        ->and($environment)->toContain('INERTIA_SSR_ENSURE_RUNTIME_EXISTS=true');
});

test('production documentation explains SSR rollout and rollback', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->toContain('## Production deployment')
        ->and($readme)->toContain('docker compose --env-file .env up -d')
        ->and($readme)->toContain('php artisan inertia:check-ssr')
        ->and($readme)->toContain('docker compose --env-file .env stop ssr')
        ->and($readme)->toContain('INERTIA_SSR_ENABLED=false');
});

test('production entrypoint seeds action types after migrations on both paths', function (): void {
    $entrypoint = (string) file_get_contents(base_path('docker/production/entrypoint.sh'));

    // Shared function runs migrate then the seeder, in that order.
    preg_match('/run_migrations\(\)\s*\{(.*?)\n\}/s', $entrypoint, $fn);
    expect($fn)->not->toBeEmpty();
    $migratePos = mb_strpos($fn[1], 'php artisan migrate --force --isolated');
    $seedPos = mb_strpos($fn[1], 'php artisan db:seed --class=ActionTypeConfigSeeder --force');
    expect($migratePos)->not->toBeFalse()
        ->and($seedPos)->not->toBeFalse()
        ->and($seedPos)->toBeGreaterThan($migratePos);

    // RUN_MIGRATIONS=true path calls the shared function. Anchor on the `if`
    // statement itself so the match cannot start inside run_migrations() or a
    // comment mentioning the variable.
    preg_match('/if \[\[ "\$\{RUN_MIGRATIONS[^\n]*\n(.*?)\nfi\b/s', $entrypoint, $runBlock);
    expect($runBlock)->not->toBeEmpty()
        ->and($runBlock[1])->toContain('run_migrations')
        ->and($runBlock[1])->not->toContain('php artisan migrate');

    // Dedicated migrate role calls the shared function.
    preg_match('/\s+migrate\)(.*?)\s+;;/s', $entrypoint, $migrateBlock);
    expect($migrateBlock)->not->toBeEmpty()
        ->and($migrateBlock[1])->toContain('run_migrations');
});

function dockerConfigRoleBlock(string $script, string $role): string
{
    preg_match(sprintf('/\n\s+%s\)(.*?)\n\s+;;/s', preg_quote($role, '/')), $script, $block);

    return $block[1] ?? '';
}

function dockerConfigComposeService(string $compose, string $service): string
{
    preg_match(sprintf('/\n  %s:\n(.*?)(?=\n  [a-z][a-z-]+:|\nvolumes:)/s', preg_quote($service, '/')), $compose, $block);

    return $block[1] ?? '';
}

test('the general queue worker drains every lane unless compose narrows it', function (): void {
    $entrypoint = (string) file_get_contents(base_path('docker/production/entrypoint.sh'));
    $compose = (string) file_get_contents(base_path('docker/production/compose.yaml'));
    $generalLanes = implode(',', array_map(static fn (QueueLane $queueLane): string => $queueLane->value, QueueLane::generalLanes()));

    expect(dockerConfigRoleBlock($entrypoint, 'queue'))
        ->toContain(sprintf('--queue="${QUEUE_LANES:-%s}"', implode(',', QueueLane::values())))
        ->and(dockerConfigComposeService($compose, 'queue'))
        ->toContain('CONTAINER_ROLE: queue')
        ->toContain(sprintf('QUEUE_LANES: %s', $generalLanes));
});

test('the ai lane runs in its own worker service with a timeout under retry_after', function (): void {
    $entrypoint = (string) file_get_contents(base_path('docker/production/entrypoint.sh'));
    $compose = (string) file_get_contents(base_path('docker/production/compose.yaml'));

    $aiRole = dockerConfigRoleBlock($entrypoint, 'queue-ai');
    $aiService = dockerConfigComposeService($compose, 'queue-ai');
    preg_match('/--timeout=(\d+)/', $aiRole, $timeout);
    preg_match('/stop_grace_period:\s*(\d+)s/', $aiService, $gracePeriod);

    expect($aiRole)->toContain('exec php artisan queue:work')->toContain('--queue=ai')
        ->and($timeout)->not->toBeEmpty()
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan((int) $timeout[1])
        ->and($aiService)->toContain('CONTAINER_ROLE: queue-ai')->not->toContain('ports:')
        ->and($gracePeriod)->not->toBeEmpty()
        ->and((int) $gracePeriod[1])->toBeGreaterThan((int) $timeout[1])
        ->and($entrypoint)->toContain('Valid roles: web, queue, queue-ai, scheduler, ssr, reverb, migrate');
});

test('the dev stack worker drains every lane', function (): void {
    $compose = (string) file_get_contents(base_path('compose.yaml'));
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lanes = implode(',', QueueLane::values());

    preg_match("/\n    queue:\n(.*?)(?=\n    [a-z][a-z.-]+:\n)/s", $compose, $devQueue);

    expect($devQueue[1] ?? '')->toContain(sprintf("'--queue=%s'", $lanes))
        ->and(implode(' ', $composer['scripts']['dev']))->toContain(sprintf('queue:listen --queue=%s', $lanes));
});

test('the octane smoke job boots the queue-ai role', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect(mb_substr_count($ci, 'for role in queue queue-ai scheduler ssr reverb; do'))->toBe(2)
        ->and($ci)->toContain('docker rm -f octane queue queue-ai scheduler ssr reverb');
});
