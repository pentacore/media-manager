<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Settings\AiSettings;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('defaults come from config when nothing is persisted', function (): void {
    config()->set('mediamanager.ai.mode', 'executive');
    config()->set('mediamanager.ai.model', 'gpt-5-mini');

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->mode())->toBe(AiMode::Executive);
    expect($aiSettings->model())->toBe('gpt-5-mini');
});

test('setMode persists and is read back', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setMode(AiMode::Advisory);

    expect($aiSettings->mode())->toBe(AiMode::Advisory);
    $this->assertDatabaseHas('app_settings', ['key' => 'ai.mode']);
});

test('setModel persists and is read back', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setModel('claude-haiku-4-5');

    expect($aiSettings->model())->toBe('claude-haiku-4-5');
    $this->assertDatabaseHas('app_settings', ['key' => 'ai.model']);
});

test('invalid stored mode falls back to Executive', function (): void {
    resolve(AppSettings::class)->set('ai.mode', 'gibberish');

    expect(resolve(AiSettings::class)->mode())->toBe(AiMode::Executive);
});

test('withMode override is shared within a request scope', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->withMode(AiMode::Advisory);

    // Later resolutions in the same scope (orchestrator, tools) must see
    // the same instance and therefore the per-request override.
    expect(resolve(AiSettings::class))->toBe($aiSettings)
        ->and(resolve(AiSettings::class)->mode())->toBe(AiMode::Advisory);
});

test('withMode override does not leak across request scopes', function (): void {
    config()->set('mediamanager.ai.mode', 'executive');

    resolve(AiSettings::class)->withMode(AiMode::Advisory);

    // Octane flushes scoped instances between requests; a singleton would
    // carry one request's Advisory override into every later request.
    app()->forgetScopedInstances();

    expect(resolve(AiSettings::class)->mode())->toBe(AiMode::Executive);
});
