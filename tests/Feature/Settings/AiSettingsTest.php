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

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->mode())->toBe(AiMode::Executive);
});

test('setMode persists and is read back', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setMode(AiMode::Advisory);

    expect($aiSettings->mode())->toBe(AiMode::Advisory);
    $this->assertDatabaseHas('app_settings', ['key' => 'ai.mode']);
});

test('chatTimeout falls back to the config default when nothing is persisted', function (): void {
    config()->set('mediamanager.ai.chat_timeout', 120);

    expect(resolve(AiSettings::class)->chatTimeout())->toBe(120);
});

test('setChatTimeout persists and overrides the config default', function (): void {
    config()->set('mediamanager.ai.chat_timeout', 120);
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setChatTimeout(300);

    expect($aiSettings->chatTimeout())->toBe(300);
    $this->assertDatabaseHas('app_settings', ['key' => 'ai.chat_timeout']);
});

test('setChatTimeout with null clears the override back to the config default', function (): void {
    config()->set('mediamanager.ai.chat_timeout', 120);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setChatTimeout(300);

    $aiSettings->setChatTimeout(null);

    expect($aiSettings->chatTimeout())->toBe(120);
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

test('chatTimeout is capped below the Octane request ceiling', function (): void {
    config()->set('octane.max_execution_time', 120);
    config()->set('mediamanager.ai.chat_timeout', 120);

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->chatTimeout())->toBe(110);

    $aiSettings->setChatTimeout(600);

    expect($aiSettings->chatTimeout())->toBe(110);
});

test('chatTimeout keeps a value already below the Octane ceiling', function (): void {
    config()->set('octane.max_execution_time', 150);
    config()->set('mediamanager.ai.chat_timeout', 120);

    expect(resolve(AiSettings::class)->chatTimeout())->toBe(120);
});

test('chatTimeout is not capped when Octane has no request ceiling', function (): void {
    config()->set('octane.max_execution_time', 0);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setChatTimeout(600);

    expect($aiSettings->chatTimeout())->toBe(600);
});
