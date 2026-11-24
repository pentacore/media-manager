<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function ciWorkflowFiles(): array
{
    return [
        ...glob(base_path('.github/workflows/*.yml')) ?: [],
        ...glob(base_path('.github/actions/*/action.yml')) ?: [],
    ];
}

test('every third-party action is pinned to a full commit sha with its exact version', function (): void {
    $unpinned = collect(ciWorkflowFiles())
        ->flatMap(function (string $path): array {
            preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', (string) file_get_contents($path), $matches, PREG_SET_ORDER);

            return array_map(
                static fn (array $match): string => sprintf('%s: %s%s', basename(dirname($path)).'/'.basename($path), $match[1], rtrim($match[2])),
                $matches,
            );
        })
        ->reject(fn (string $line): bool => str_contains($line, ': ./'))
        ->reject(fn (string $line): bool => preg_match('/@[0-9a-f]{40} # v?\d+\.\d+\.\d+$/', $line) === 1)
        ->values()
        ->all();

    expect($unpinned)->toBe([]);
});

test('ci runs with a read-only token by default', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))
        ->toMatch('/^permissions:\n  contents: read\n/m');
});

test('ci audits runtime dependencies as a gate and dev dependencies as a report', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('run: composer audit --locked --no-dev --abandoned=report')
        ->toContain('run: pnpm audit --prod --audit-level high')
        ->toMatch('/continue-on-error: true\n\s+run: composer audit --locked --abandoned=report/')
        ->toMatch('/continue-on-error: true\n\s+run: pnpm audit --audit-level high/');
});

test('ci installs with pnpm and runs the vite+ check and vue-tsc', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('run: pnpm install --frozen-lockfile')
        ->toContain('run: pnpm run check')
        ->toContain('run: pnpm run types:check')
        ->not->toMatch('/(?<!p)npm (ci|run)\b/');
});

test('ci fails on rector drift', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))
        ->toContain('run: vendor/bin/rector process --dry-run --no-progress-bar');
});

test('ci runs larastan at level 7 with no baseline', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $config = (string) file_get_contents(base_path('phpstan.neon'));

    expect($ci)->toContain('run: vendor/bin/phpstan analyse --memory-limit=2G --no-progress')
        ->and($config)->toContain('vendor/larastan/larastan/extension.neon')
        ->toContain('vendor/nesbot/carbon/extension.neon')
        ->not->toContain('phpstan-baseline.neon')
        ->toMatch('/level: 7\b/')
        ->and(base_path('phpstan-baseline.neon'))->not->toBeFile();
});

test('ci reports unit and feature coverage without gating on it', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain("coverage: \${{ matrix.suite == 'unit-feature' && 'pcov' || 'none' }}")
        ->toContain('--exclude-testsuite=Browser --coverage-clover=build/coverage/clover.xml')
        ->toContain('run: php .github/scripts/coverage-summary.php build/coverage/clover.xml >> "$GITHUB_STEP_SUMMARY"')
        ->not->toContain('--min=');
});

test('ci, the release, sail and both production stages run node 26', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
    $sail = (string) file_get_contents(base_path('docker/8.5/Dockerfile'));
    $production = (string) file_get_contents(base_path('docker/production/Dockerfile'));

    // Alpine's `nodejs` is the LTS line (24); `nodejs-current` is Node 26.
    expect($ci)->toContain("NODE_VERSION: '26'")
        ->and($release)->toContain("node-version: '26'")
        ->and($sail)->toContain('ARG NODE_VERSION=26')
        ->and(mb_substr_count($production, 'nodejs-current'))->toBe(2)
        ->and($production)->not->toMatch('/^\s+nodejs \\\\$/m');
});
