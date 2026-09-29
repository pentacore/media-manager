<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
});

test('failover provider persists and reads back', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->failoverProvider())->toBeNull();

    $aiSettings->setFailoverProvider(Lab::Anthropic);
    expect($aiSettings->failoverProvider())->toBe(Lab::Anthropic);

    $aiSettings->setFailoverProvider(null);
    expect($aiSettings->failoverProvider())->toBeNull();
});

test('an agent on the failover path resolves without exception', function (): void {
    resolve(AiSettings::class)->setFailoverProvider(Lab::Anthropic);

    MediaAgent::fake(['ok']);

    $agentResponse = (new MediaAgent)->prompt('hello');

    expect($agentResponse->text)->toBe('ok');

    MediaAgent::assertPrompted('hello');
});
