<?php

declare(strict_types=1);

use App\Models\AiUsageRecord;
use App\Models\BenchmarkRun;
use Illuminate\Support\Facades\Schema;

function isBenchmarkMigration(): object
{
    return require database_path('migrations/2026_10_12_100500_add_is_benchmark_to_ai_usage_records_table.php');
}

test('the backfill tags rows that already belong to a run', function (): void {
    $run = BenchmarkRun::factory()->create();
    $tagged = AiUsageRecord::factory()->create(['benchmark_run_id' => $run->id]);
    $plain = AiUsageRecord::factory()->create();

    expect($tagged->fresh()->is_benchmark)->toBeFalse();

    isBenchmarkMigration()->down();
    isBenchmarkMigration()->up();

    expect($tagged->fresh()->is_benchmark)->toBeTrue()
        ->and($plain->fresh()->is_benchmark)->toBeFalse();
});

test('down drops the column and up restores it', function (): void {
    isBenchmarkMigration()->down();

    expect(Schema::hasColumn('ai_usage_records', 'is_benchmark'))->toBeFalse();

    isBenchmarkMigration()->up();

    expect(Schema::hasColumn('ai_usage_records', 'is_benchmark'))->toBeTrue();
});
