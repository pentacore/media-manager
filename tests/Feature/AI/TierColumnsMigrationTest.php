<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function tierMigrationInstance(): object
{
    return require database_path('migrations/2026_10_08_100000_add_tier_columns_to_ai_task_models_table.php');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tierMigrationRow(array $attributes): void
{
    DB::table('ai_task_models')->insert([
        'scope' => 'default',
        'provider' => null,
        'model' => null,
        'reasoning' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    DB::table('ai_task_models')->delete();
});

test('existing rows sit at position 0 with no conditions', function (): void {
    tierMigrationRow(['task' => 'chat', 'provider' => 'openai', 'model' => 'gpt-5.6-luna']);

    $row = DB::table('ai_task_models')->where('task', 'chat')->first();

    expect($row)->toMatchObject(['position' => 0, 'min_pool_percent' => null, 'min_pool_tokens' => null]);
});

test('down keeps position 0 rows and restores one row per task scope', function (): void {
    tierMigrationRow(['task' => 'chat', 'model' => 'gpt-5.6-luna', 'provider' => 'openai']);
    tierMigrationRow(['task' => 'chat', 'model' => 'gpt-5-nano', 'provider' => 'openai', 'position' => 1]);

    tierMigrationInstance()->down();

    expect(DB::table('ai_task_models')->where('task', 'chat')->pluck('model')->all())->toBe(['gpt-5.6-luna']);

    tierMigrationInstance()->up();
});

test('event rows with a model and no reasoning get the decision reasoning copied', function (): void {
    tierMigrationInstance()->down();

    $legacyRow = static fn (array $attributes): bool => DB::table('ai_task_models')->insert([
        'scope' => 'default', 'provider' => null, 'model' => null, 'reasoning' => null,
        'created_at' => now(), 'updated_at' => now(), ...$attributes,
    ]);
    $legacyRow(['task' => 'decision', 'reasoning' => 'high']);
    $legacyRow(['task' => 'decision', 'scope' => 'sonarr:Download', 'provider' => 'openai', 'model' => 'gpt-5-nano']);
    $legacyRow(['task' => 'decision', 'scope' => 'radarr:Grab', 'reasoning' => 'none']);
    $legacyRow(['task' => 'decision', 'scope' => 'sonarr:Grab', 'provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'low']);

    tierMigrationInstance()->up();

    $reasoning = DB::table('ai_task_models')->where('task', 'decision')->orderBy('id')->pluck('reasoning', 'scope')->all();

    expect($reasoning)->toBe([
        'default' => 'high',
        'sonarr:Download' => 'high',
        'radarr:Grab' => 'none',
        'sonarr:Grab' => 'low',
    ]);
});

test('without a decision reasoning nothing is copied', function (): void {
    tierMigrationInstance()->down();

    DB::table('ai_task_models')->insert([
        'task' => 'decision', 'scope' => 'sonarr:Download', 'provider' => 'openai', 'model' => 'gpt-5-nano',
        'reasoning' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    tierMigrationInstance()->up();

    expect(DB::table('ai_task_models')->where('scope', 'sonarr:Download')->value('reasoning'))->toBeNull();
});
