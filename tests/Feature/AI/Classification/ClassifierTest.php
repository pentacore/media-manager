<?php

declare(strict_types=1);

use App\Ai\Classification\Classifier;
use App\Enums\AiUsageKind;
use App\Jobs\RunDecisionAgent;
use App\Models\AiUsageRecord;
use App\Settings\AiSettings;
use App\Settings\OpenRouterSettings;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

test('probability returns the boolean answer probability and bills a classification row', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.82)]]);

    expect(resolve(Classifier::class)->probability(RunDecisionAgent::class, 'payload', 'Needs action?'))->toBe(0.82);

    expect(AiUsageRecord::where('kind', AiUsageKind::Classification)->sole()->agent_class)->toBe(RunDecisionAgent::class);
});

test('the classification row stores the state and questions as input and the answers as output', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.82)]]);

    resolve(Classifier::class)->probability(RunDecisionAgent::class, 'payload', 'Needs action?');

    $record = AiUsageRecord::where('kind', AiUsageKind::Classification)->sole();

    expect(json_decode((string) $record->prompt_text, true))->toBe([
        'state' => 'payload',
        'questions' => ['decision' => ['type' => 'boolean', 'instructions' => 'Needs action?']],
    ])
        ->and(json_decode((string) $record->response_text, true))->toBe(['decision' => new BooleanAnswer(0.82)->toArray()]);
});

test('the classifier fails open when the provider has no key', function (): void {
    config()->set('ai.providers.openrouter.key');

    expect(resolve(Classifier::class)->probability('x', 'payload', 'Needs action?'))->toBeNull();
});

test('the classifier fails open when classification throws', function (): void {
    Classification::fake(fn () => throw new RuntimeException('down'));

    expect(resolve(Classifier::class)->probability('x', 'payload', 'Needs action?'))->toBeNull();
});

test('an OpenRouter classification call carries the routing preferences', function (): void {
    resolve(OpenRouterSettings::class)->setDenyDataCollection(true);
    Classification::fake([['decision' => new BooleanAnswer(0.5)]]);

    resolve(Classifier::class)->probability('x', 'payload', 'Needs action?');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->providerOptions === ['provider' => ['data_collection' => 'deny']]);
});

test('a non-OpenRouter classification call carries no routing preferences', function (): void {
    resolve(OpenRouterSettings::class)->setDenyDataCollection(true);
    config()->set('ai.providers.typesafe.key', 'test-key');
    resolve(AiSettings::class)->setClassificationProvider('typesafe');
    Classification::fake([['decision' => new BooleanAnswer(0.5)]]);

    resolve(Classifier::class)->probability('x', 'payload', 'Needs action?');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->providerOptions === []);
});

test('background classification calls use the five second timeout by default', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.5)]]);

    resolve(Classifier::class)->probability('x', 'payload', 'Needs action?');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->timeout === Classifier::BACKGROUND_TIMEOUT_SECONDS);
});

test('a caller can pass its own classification timeout', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.5)]]);

    resolve(Classifier::class)->probability('x', 'payload', 'Needs action?', Classifier::CHAT_TIMEOUT_SECONDS);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->timeout === 3);
});
