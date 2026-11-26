<?php

declare(strict_types=1);

use App\Ai\Benchmark\ToolArguments;
use App\Models\BenchmarkCase;

function stuckImportFixtureMigration(): object
{
    return require database_path('migrations/2026_11_26_120000_migrate_single_download_stuck_import_fixtures.php');
}

/**
 * @return array{tool: string, arguments: array<string, mixed>, result: string}
 */
function stuckImportLegacyFixture(string $downloadId = 'SABnzbd_nzo_abc'): array
{
    return [
        'tool' => 'InspectStuckImportTool',
        'arguments' => ['service' => 'sonarr', 'download_id' => $downloadId],
        'result' => json_encode([
            'ok' => true,
            'service' => 'sonarr',
            'download_id' => $downloadId,
            'total' => 1,
            'importable' => 1,
            'fully_mapped' => true,
            'files' => [['path' => '/downloads/Show.S01E01.mkv', 'mapped' => true, 'rejections' => []]],
        ], JSON_UNESCAPED_UNICODE),
    ];
}

test('a single download_id fixture becomes a one-id download_ids call with the current result shape', function (): void {
    $legacy = stuckImportLegacyFixture();
    $benchmarkCase = BenchmarkCase::factory()->create(['fixtures' => [$legacy]]);

    stuckImportFixtureMigration()->up();

    $fixture = $benchmarkCase->fresh()->fixtures[0];
    $inspection = json_decode($legacy['result'], true);

    expect($fixture['tool'])->toBe('InspectStuckImportTool')
        ->and(ToolArguments::same($fixture['arguments'], ['service' => 'sonarr', 'download_ids' => ['SABnzbd_nzo_abc']]))->toBeTrue()
        ->and($fixture['arguments'])->not->toHaveKey('download_id')
        ->and(json_decode($fixture['result'], true))->toBe(['ok' => true, 'service' => 'sonarr', 'downloads' => [$inspection]]);
});

test('a legacy lookup failure keeps its result, which both shapes share', function (): void {
    $failure = ['tool' => 'InspectStuckImportTool', 'arguments' => ['service' => 'radarr', 'download_id' => 'x1'], 'result' => '{"ok":false,"reason":"lookup_failed","message":"Could not enumerate import candidates."}'];
    $benchmarkCase = BenchmarkCase::factory()->create(['fixtures' => [$failure]]);

    stuckImportFixtureMigration()->up();

    $fixture = $benchmarkCase->fresh()->fixtures[0];

    expect(ToolArguments::same($fixture['arguments'], ['service' => 'radarr', 'download_ids' => ['x1']]))->toBeTrue()
        ->and($fixture['result'])->toBe($failure['result']);
});

test('other tools, current fixtures and other fixtures of the case are left alone', function (): void {
    $verdict = ['tool' => 'StuckImportVerdictTool', 'arguments' => ['service' => 'sonarr', 'download_id' => 'v1'], 'result' => '{"ok":true}'];
    $current = ['tool' => 'InspectStuckImportTool', 'arguments' => ['service' => 'sonarr', 'download_ids' => ['a', 'b']], 'result' => '{"ok":true,"service":"sonarr","downloads":[]}'];
    $status = ['tool' => 'GetServiceStatusTool', 'arguments' => [], 'result' => '{"services":[]}'];
    $benchmarkCase = BenchmarkCase::factory()->create(['fixtures' => [$verdict, $current, $status, stuckImportLegacyFixture('z9')]]);
    $untouched = BenchmarkCase::factory()->create(['fixtures' => [$verdict, $current]]);
    $updatedAt = $untouched->fresh()->updated_at;

    stuckImportFixtureMigration()->up();

    $fixtures = $benchmarkCase->fresh()->fixtures;

    // jsonb reorders object keys, so compare as maps.
    expect($fixtures[0])->toEqual($verdict)
        ->and($fixtures[1])->toEqual($current)
        ->and($fixtures[2])->toEqual($status)
        ->and($fixtures[3]['arguments'])->toHaveKey('download_ids')
        ->and($untouched->fresh()->fixtures)->toEqual([$verdict, $current])
        ->and($untouched->fresh()->updated_at?->equalTo($updatedAt))->toBeTrue();
});

test('running the migration twice changes nothing more', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->create(['fixtures' => [stuckImportLegacyFixture()]]);

    stuckImportFixtureMigration()->up();
    $once = $benchmarkCase->fresh()->fixtures;
    stuckImportFixtureMigration()->up();

    expect($benchmarkCase->fresh()->fixtures)->toBe($once);
});
