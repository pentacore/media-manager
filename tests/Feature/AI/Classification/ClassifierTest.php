<?php

declare(strict_types=1);

use App\Ai\Classification\Classifier;
use App\Enums\AiUsageKind;
use App\Models\AiUsageRecord;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

test('probability returns the boolean answer probability and bills a classification row', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.82)]]);

    expect(resolve(Classifier::class)->probability('App\Jobs\RunDecisionAgent', 'payload', 'Needs action?'))->toBe(0.82);

    expect(AiUsageRecord::where('kind', AiUsageKind::Classification)->sole()->agent_class)->toBe('App\Jobs\RunDecisionAgent');
});

test('the classifier fails open when the provider has no key', function (): void {
    config()->set('ai.providers.openrouter.key', null);

    expect(resolve(Classifier::class)->probability('x', 'payload', 'Needs action?'))->toBeNull();
});

test('the classifier fails open when classification throws', function (): void {
    Classification::fake(fn () => throw new RuntimeException('down'));

    expect(resolve(Classifier::class)->probability('x', 'payload', 'Needs action?'))->toBeNull();
});
