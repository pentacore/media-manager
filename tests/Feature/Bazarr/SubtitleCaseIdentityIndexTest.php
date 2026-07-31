<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseStatus;
use App\Models\SubtitleCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function subtitleCaseIdentityMigration(): Migration
{
    return require database_path('migrations/2026_07_31_073245_allow_superseded_subtitle_case_identities.php');
}

function subtitleCaseIdentityIndexDefinition(): string
{
    return (string) DB::table('pg_indexes')
        ->where('indexname', 'subtitle_cases_material_identity_unique')
        ->value('indexdef');
}

test('the partial index lets a superseded row and a live row share one identity', function (): void {
    $superseded = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::Superseded,
        'superseded_at' => now(),
    ]);

    $live = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $superseded->bazarr_connection_id,
        'service_connection_id' => $superseded->service_connection_id,
        'file_fingerprint' => $superseded->file_fingerprint,
        'requirements_fingerprint' => $superseded->requirements_fingerprint,
        'status' => SubtitleCaseStatus::Observing,
    ]);

    expect($live->exists)->toBeTrue()
        ->and($live->id)->not->toBe($superseded->id);
});

test('the rollback refuses to restore the full constraint over a reopened identity', function (): void {
    $superseded = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::Superseded,
        'superseded_at' => now(),
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $superseded->bazarr_connection_id,
        'service_connection_id' => $superseded->service_connection_id,
        'file_fingerprint' => $superseded->file_fingerprint,
        'requirements_fingerprint' => $superseded->requirements_fingerprint,
        'status' => SubtitleCaseStatus::Observing,
    ]);

    // Silently recreating the old constraint would fail with a bare duplicate-key
    // error mid-rollback; the operator is told which state blocks it instead.
    expect(fn (): mixed => subtitleCaseIdentityMigration()->down())
        ->toThrow(RuntimeException::class, 'reopened');
});

test('the rollback reports how many identities were reopened not how many rows', function (): void {
    // Two reopened identities: the first with three rows, so a count that aggregated
    // the first group instead of counting groups would report "3 identities".
    foreach ([2, 1] as $index => $supersededRows) {
        $live = SubtitleCase::factory()->create([
            'file_fingerprint' => hash('sha256', 'identity-'.$index),
            'status' => SubtitleCaseStatus::Observing,
        ]);

        foreach (range(1, $supersededRows) as $ignored) {
            SubtitleCase::factory()->create([
                'bazarr_connection_id' => $live->bazarr_connection_id,
                'service_connection_id' => $live->service_connection_id,
                'file_fingerprint' => $live->file_fingerprint,
                'requirements_fingerprint' => $live->requirements_fingerprint,
                'status' => SubtitleCaseStatus::Superseded,
                'superseded_at' => now(),
            ]);
        }
    }

    expect(fn (): mixed => subtitleCaseIdentityMigration()->down())
        ->toThrow(RuntimeException::class, '2 identities');
});

test('the rollback restores the full constraint while every identity is unique', function (): void {
    SubtitleCase::factory()->create(['status' => SubtitleCaseStatus::Observing]);
    $migration = subtitleCaseIdentityMigration();

    $migration->down();

    // The restored index covers every row rather than only the non-superseded ones.
    expect(subtitleCaseIdentityIndexDefinition())->not->toContain('WHERE');

    $migration->up();

    expect(subtitleCaseIdentityIndexDefinition())->toContain('superseded');
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'The index definition is read from pg_indexes.',
);
