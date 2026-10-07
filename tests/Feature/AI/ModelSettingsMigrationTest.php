<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function modelMigrationInstance(): object
{
    return require database_path('migrations/2026_10_07_100100_migrate_model_settings_to_ai_task_models.php');
}

function modelMigrationSeedSetting(string $key, mixed $value): void
{
    DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
}

function modelMigrationTaskRow(string $task, string $scope = 'default'): ?object
{
    return DB::table('ai_task_models')->where('task', $task)->where('scope', $scope)->first();
}

beforeEach(function (): void {
    // RefreshDatabase already ran the migration on an empty app_settings;
    // start each case from a clean table.
    DB::table('ai_task_models')->delete();
});

test('saved selections become task rows and the old keys are removed', function (): void {
    modelMigrationSeedSetting('ai.model', 'gpt-5.6-luna');
    modelMigrationSeedSetting('ai.model_provider', 'openai');
    modelMigrationSeedSetting('ai.advisor_reasoning_level', 'medium');
    modelMigrationSeedSetting('ai.title_model', 'auto');
    modelMigrationSeedSetting('ai.sub_agent_model', 'gpt-5-nano');
    modelMigrationSeedSetting('ai.sub_agent_model_provider', 'openai');
    modelMigrationSeedSetting('ai.pricing.updater_model', 'gpt-5.6-luna');
    modelMigrationSeedSetting('decision_agent.model', 'claude-sonnet-5-5');
    modelMigrationSeedSetting('decision_agent.model_provider', 'anthropic');
    modelMigrationSeedSetting('decision_agent.reasoning_level', 'high');
    modelMigrationSeedSetting('ai.failover_provider', 'openrouter');
    modelMigrationSeedSetting('ai.failover_model', '');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'medium'])
        ->and(modelMigrationTaskRow('title'))->toMatchObject(['provider' => null, 'model' => 'auto'])
        ->and(modelMigrationTaskRow('file_inspector'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5-nano'])
        ->and(modelMigrationTaskRow('stuck_download_investigator'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5-nano'])
        ->and(modelMigrationTaskRow('price_updater'))->toMatchObject(['model' => 'gpt-5.6-luna'])
        ->and(modelMigrationTaskRow('decision'))->toMatchObject(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'reasoning' => 'high'])
        ->and(modelMigrationTaskRow('failover'))->toMatchObject(['provider' => 'openrouter', 'model' => null])
        ->and(DB::table('app_settings')->whereIn('key', ['ai.model', 'ai.sub_agent_model', 'decision_agent.reasoning_level', 'ai.failover_provider'])->count())->toBe(0);
});

test('an existing install without saved reasoning keeps its old effective level', function (): void {
    config()->set('mediamanager.ai.advisor_reasoning_level', null);
    config()->set('mediamanager.decision_agent.reasoning_level', '');
    modelMigrationSeedSetting('ai.mode', 'executive');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat')?->reasoning)->toBe('none')
        ->and(modelMigrationTaskRow('decision')?->reasoning)->toBe('none');
});

test('a fresh install gets no rows', function (): void {
    modelMigrationInstance()->up();

    expect(DB::table('ai_task_models')->count())->toBe(0);
});

test('down restores the keys from the rows', function (): void {
    modelMigrationSeedSetting('ai.model', 'gpt-5.6-luna');
    modelMigrationSeedSetting('ai.sub_agent_model', 'gpt-5-nano');
    modelMigrationSeedSetting('decision_agent.reasoning_level', 'high');

    $migration = modelMigrationInstance();
    $migration->up();
    $migration->down();

    $value = fn (string $key): mixed => json_decode((string) DB::table('app_settings')->where('key', $key)->value('value'), true);

    expect($value('ai.model'))->toBe('gpt-5.6-luna')
        ->and($value('ai.sub_agent_model'))->toBe('gpt-5-nano')
        ->and($value('decision_agent.reasoning_level'))->toBe('high')
        ->and(DB::table('ai_task_models')->count())->toBe(0);
});
