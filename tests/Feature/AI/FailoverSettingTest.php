<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
});

test('providerChain is null without failover setting', function (): void {
    expect(resolve(AiSettings::class)->providerChain())->toBeNull();
});

test('providerChain returns primary then failover', function (): void {
    $settings = resolve(AiSettings::class);
    $settings->setFailoverProvider(Lab::Anthropic);

    config()->set('ai.default', 'openai');

    expect($settings->providerChain())->toBe([Lab::OpenAI, Lab::Anthropic]);
});

test('failover equal to primary collapses to null', function (): void {
    $settings = resolve(AiSettings::class);
    $settings->setFailoverProvider(Lab::OpenAI);

    config()->set('ai.default', 'openai');

    expect($settings->providerChain())->toBeNull();
});

test('failover provider persists and reads back', function (): void {
    $settings = resolve(AiSettings::class);

    expect($settings->failoverProvider())->toBeNull();

    $settings->setFailoverProvider(Lab::Anthropic);
    expect($settings->failoverProvider())->toBe(Lab::Anthropic);

    $settings->setFailoverProvider(null);
    expect($settings->failoverProvider())->toBeNull();
});

test('providerChainWithModel keeps the configured model on the primary and defaults the failover', function (): void {
    $settings = resolve(AiSettings::class);
    $settings->setFailoverProvider(Lab::Anthropic);

    config()->set('ai.default', 'openai');

    // Per-provider map: primary keeps its explicit model so the OpenAI model
    // never leaks to the Anthropic failover (which uses its own default: null).
    expect($settings->providerChainWithModel('gpt-5-mini'))->toBe([
        Lab::OpenAI->value => 'gpt-5-mini',
        Lab::Anthropic->value => null,
    ]);
});

test('providerChainWithModel is null without failover setting', function (): void {
    expect(resolve(AiSettings::class)->providerChainWithModel('gpt-5-mini'))->toBeNull();
});

test('an agent on the failover path resolves without exception', function (): void {
    // Integration guard for the model-leak concern documented in the task:
    // when a per-provider failover map is passed, the agent-defined model()
    // (an OpenAI model) is scoped to the primary and does NOT leak to the
    // failover provider, so the prompt path never throws.
    $settings = resolve(AiSettings::class);
    $settings->setFailoverProvider(Lab::Anthropic);
    config()->set('ai.default', 'openai');

    MediaAgent::fake(['ok']);

    $chain = $settings->providerChainWithModel(resolve(AiSettings::class)->model());

    $response = (new MediaAgent)->prompt('hello', provider: $chain);

    expect($response->text)->toBe('ok');

    MediaAgent::assertPrompted('hello');
});
