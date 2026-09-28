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
        ->toContain('run: npm audit --omit=dev --audit-level=high --package-lock-only')
        ->toMatch('/continue-on-error: true\n\s+run: composer audit --locked --abandoned=report/')
        ->toMatch('/continue-on-error: true\n\s+run: npm audit --audit-level=high --package-lock-only/');
});

test('ci fails on rector drift', function (): void {
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))
        ->toContain('run: vendor/bin/rector process --dry-run --no-progress-bar');
});

test('ci runs larastan at level 6 against the committed baseline', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $config = (string) file_get_contents(base_path('phpstan.neon'));

    expect($ci)->toContain('run: vendor/bin/phpstan analyse --memory-limit=2G --no-progress')
        ->and($config)->toContain('vendor/larastan/larastan/extension.neon')
        ->toContain('phpstan-baseline.neon')
        ->toMatch('/level: 6\b/')
        ->and(base_path('phpstan-baseline.neon'))->toBeFile();
});
