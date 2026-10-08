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
    config()->set('ai.default', 'openai');
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
        ->and(modelMigrationTaskRow('title'))->toMatchObject(['provider' => 'openai', 'model' => 'auto'])
        ->and(modelMigrationTaskRow('file_inspector'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5-nano'])
        ->and(modelMigrationTaskRow('stuck_download_investigator'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5-nano'])
        ->and(modelMigrationTaskRow('price_updater'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5.6-luna'])
        ->and(modelMigrationTaskRow('decision'))->toMatchObject(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'reasoning' => 'provider_default'])
        ->and(modelMigrationTaskRow('failover'))->toMatchObject(['provider' => 'openrouter', 'model' => null])
        ->and(DB::table('app_settings')->whereIn('key', ['ai.model', 'ai.sub_agent_model', 'decision_agent.reasoning_level', 'ai.failover_provider'])->count())->toBe(0);
});

test('an existing install without saved reasoning keeps its old effective level', function (): void {
    config()->set('mediamanager.ai.advisor_reasoning_level');
    config()->set('mediamanager.decision_agent.reasoning_level', '');
    modelMigrationSeedSetting('ai.mode', 'executive');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat')?->reasoning)->toBe('none')
        ->and(modelMigrationTaskRow('decision')?->reasoning)->toBe('none');
});

test('a model saved without a provider gets the default provider written out', function (): void {
    config()->set('ai.default', 'anthropic');
    modelMigrationSeedSetting('ai.model', 'claude-sonnet-5-5');
    modelMigrationSeedSetting('ai.sub_agent_model', 'claude-haiku-4-5');
    modelMigrationSeedSetting('ai.failover_model', 'gpt-5-mini');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat'))->toMatchObject(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'reasoning' => 'provider_default'])
        ->and(modelMigrationTaskRow('file_inspector'))->toMatchObject(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'])
        ->and(modelMigrationTaskRow('failover'))->toMatchObject(['provider' => null, 'model' => 'gpt-5-mini']);
});

test('an install on a provider that never got reasoning keeps provider default', function (): void {
    config()->set('mediamanager.ai.advisor_reasoning_level', 'high');
    config()->set('mediamanager.decision_agent.reasoning_level', 'high');
    modelMigrationSeedSetting('ai.model_provider', 'anthropic');
    modelMigrationSeedSetting('ai.model', 'claude-sonnet-5-5');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat')?->reasoning)->toBe('provider_default')
        ->and(modelMigrationTaskRow('decision')?->reasoning)->toBe('provider_default');
});

test('a saved reasoning level becomes provider default on a provider that never got it', function (): void {
    modelMigrationSeedSetting('ai.model_provider', 'gemini');
    modelMigrationSeedSetting('ai.model', 'gemini-3-pro');
    modelMigrationSeedSetting('ai.advisor_reasoning_level', 'high');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat'))->toMatchObject(['provider' => 'gemini', 'model' => 'gemini-3-pro', 'reasoning' => 'provider_default']);
});

test('an openai or openrouter install keeps the config reasoning level, else none', function (string $provider, ?string $configured, string $expected): void {
    config()->set('mediamanager.ai.advisor_reasoning_level', $configured);
    config()->set('mediamanager.decision_agent.reasoning_level', $configured);
    modelMigrationSeedSetting('ai.model_provider', $provider);
    modelMigrationSeedSetting('ai.model', 'gpt-5.6-luna');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('chat')?->reasoning)->toBe($expected)
        ->and(modelMigrationTaskRow('decision')?->reasoning)->toBe($expected);
})->with([
    'openai, config high' => ['openai', 'high', 'high'],
    'openai, config blank' => ['openai', null, 'none'],
    'openrouter, config high' => ['openrouter', 'high', 'high'],
]);

test('a decision row without its own model follows the chat provider for reasoning', function (string $chatProvider, string $expected): void {
    modelMigrationSeedSetting('ai.model_provider', $chatProvider);
    modelMigrationSeedSetting('ai.model', 'some-chat-model');
    modelMigrationSeedSetting('decision_agent.reasoning_level', 'high');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('decision'))->toMatchObject(['provider' => null, 'model' => null, 'reasoning' => $expected]);
})->with([
    'chat on anthropic' => ['anthropic', 'provider_default'],
    'chat on openrouter' => ['openrouter', 'high'],
]);

test('a decision row with its own model uses its own provider for reasoning', function (): void {
    modelMigrationSeedSetting('ai.model_provider', 'anthropic');
    modelMigrationSeedSetting('ai.model', 'claude-sonnet-5-5');
    modelMigrationSeedSetting('decision_agent.model', 'gpt-5.6-luna');
    modelMigrationSeedSetting('decision_agent.reasoning_level', 'high');

    modelMigrationInstance()->up();

    expect(modelMigrationTaskRow('decision'))->toMatchObject(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'high'])
        ->and(modelMigrationTaskRow('chat')?->reasoning)->toBe('provider_default');
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

test('down writes no reasoning key for provider default', function (): void {
    modelMigrationSeedSetting('ai.model_provider', 'gemini');
    modelMigrationSeedSetting('ai.model', 'gemini-3-pro');
    modelMigrationSeedSetting('ai.advisor_reasoning_level', 'high');
    modelMigrationSeedSetting('decision_agent.reasoning_level', 'low');

    $migration = modelMigrationInstance();
    $migration->up();

    expect(modelMigrationTaskRow('chat')?->reasoning)->toBe('provider_default')
        ->and(modelMigrationTaskRow('decision')?->reasoning)->toBe('provider_default');

    $migration->down();

    expect(DB::table('app_settings')->whereIn('key', ['ai.advisor_reasoning_level', 'decision_agent.reasoning_level'])->count())->toBe(0)
        ->and(json_decode((string) DB::table('app_settings')->where('key', 'ai.model')->value('value'), true))->toBe('gemini-3-pro');
});
