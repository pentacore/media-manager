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
    config()->set('mediamanager.ai.command_model', 'gpt-5-mini');
    config()->set('mediamanager.ai.advisor_model', 'gpt-5-mini');

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->mode())->toBe(AiMode::Executive);
    expect($aiSettings->commandModel())->toBe('gpt-5-mini');
    expect($aiSettings->advisorModel())->toBe('gpt-5-mini');
});

test('setMode persists and is read back', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setMode(AiMode::Advisory);

    expect($aiSettings->mode())->toBe(AiMode::Advisory);
    $this->assertDatabaseHas('app_settings', ['key' => 'ai.mode']);
});

test('setCommandModel and setAdvisorModel persist independently', function (): void {
    $aiSettings = resolve(AiSettings::class);

    $aiSettings->setCommandModel('claude-haiku-4-5');
    $aiSettings->setAdvisorModel('gemini-3-flash-preview');

    expect($aiSettings->commandModel())->toBe('claude-haiku-4-5');
    expect($aiSettings->advisorModel())->toBe('gemini-3-flash-preview');
});

test('invalid stored mode falls back to Executive', function (): void {
    resolve(AppSettings::class)->set('ai.mode', 'gibberish');

    expect(resolve(AiSettings::class)->mode())->toBe(AiMode::Executive);
});
