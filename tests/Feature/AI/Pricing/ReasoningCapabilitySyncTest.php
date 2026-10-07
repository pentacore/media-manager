<?php

declare(strict_types=1);

use App\Enums\AiReasoningLevel;
use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\AiModelPriceWriter;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\ReasoningCapability;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use App\Services\AiUsage\Pricing\LiteLlmPricingAdapter;
use App\Services\AiUsage\Pricing\ModelsDevPricingAdapter;
use App\Services\AiUsage\Pricing\OpenRouterPricingAdapter;
use App\Services\AiUsage\Pricing\RefreshScope;

function capabilityCandidate(string $model, ?ReasoningCapability $reasoningCapability): ModelPriceCandidate
{
    return new ModelPriceCandidate(
        provider: 'openai',
        model: $model,
        fields: [
            'input_per_mtok' => CandidatePriceField::of('1.25'),
            'output_per_mtok' => CandidatePriceField::of('10'),
        ],
        source: PricingSource::ModelsDev,
        reasoning: $reasoningCapability,
    );
}

test('models.dev effort options become levels and an effort style', function (): void {
    $capability = ReasoningCapability::fromModelsDev([
        'reasoning' => true,
        'reasoning_options' => [['type' => 'effort', 'values' => ['minimal', 'low', 'medium', 'high', 'xhigh', 'max']]],
    ]);

    expect($capability?->supported)->toBeTrue()
        ->and($capability?->levels)->toBe(['low', 'medium', 'high', 'xhigh', 'max'])
        ->and($capability?->style)->toBe(ReasoningCapability::STYLE_EFFORT);
});

test('a models.dev toggle adds none and a budget-only model gets the budget style', function (): void {
    $toggle = ReasoningCapability::fromModelsDev([
        'reasoning' => true,
        'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']], ['type' => 'toggle']],
    ]);
    $budget = ReasoningCapability::fromModelsDev([
        'reasoning' => true,
        'reasoning_options' => [['type' => 'budget_tokens', 'min' => 1024]],
    ]);

    expect($toggle?->levels)->toBe(['none', 'low', 'high'])
        ->and($budget?->levels)->toBeNull()
        ->and($budget?->style)->toBe(ReasoningCapability::STYLE_BUDGET);
});

test('a non-reasoning models.dev model is marked unsupported', function (): void {
    expect(ReasoningCapability::fromModelsDev(['reasoning' => false]))
        ->toEqual(new ReasoningCapability(false, null, null));
});

test('openrouter supported parameters decide support', function (): void {
    expect(ReasoningCapability::fromOpenRouter(['supported_parameters' => ['tools', 'reasoning']])?->supported)->toBeTrue()
        ->and(ReasoningCapability::fromOpenRouter(['supported_parameters' => ['tools']])?->supported)->toBeFalse()
        ->and(ReasoningCapability::fromOpenRouter([]))->toBeNull();
});

test('litellm effort levels and the none flag are read', function (): void {
    $capability = ReasoningCapability::fromLiteLlm([
        'supports_reasoning' => true,
        'reasoning_effort_levels' => ['low', 'medium', 'high'],
        'supports_none_reasoning_effort' => true,
    ]);

    expect($capability?->levels)->toBe(['none', 'low', 'medium', 'high']);
});

test('preferring takes each field from the first feed that has it', function (): void {
    $merged = ReasoningCapability::preferring(
        new ReasoningCapability(true, null, ReasoningCapability::STYLE_EFFORT),
        new ReasoningCapability(false, ['low', 'high'], null),
    );

    expect($merged)->toEqual(new ReasoningCapability(true, ['low', 'high'], ReasoningCapability::STYLE_EFFORT));
});

test('the writer stores capabilities on create', function (): void {
    resolve(AiModelPriceWriter::class)->write(
        capabilityCandidate('gpt-cap-create', new ReasoningCapability(true, ['low', 'high'], ReasoningCapability::STYLE_EFFORT)),
        RefreshScope::all(),
        PricingSource::ModelsDev,
    );

    $row = AiModelPrice::query()->where('model', 'gpt-cap-create')->firstOrFail();

    expect($row->supports_reasoning)->toBeTrue()
        ->and($row->reasoning_levels)->toBe(['low', 'high'])
        ->and($row->acceptedReasoningLevels())->toBe([AiReasoningLevel::Low, AiReasoningLevel::High]);
});

test('the writer refreshes capabilities on an unlocked row even when prices are unchanged', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai', 'model' => 'gpt-cap-update',
        'input_per_mtok' => '1.25', 'output_per_mtok' => '10',
        'supports_reasoning' => null,
    ]);

    $outcome = resolve(AiModelPriceWriter::class)->write(
        capabilityCandidate('gpt-cap-update', new ReasoningCapability(true, ['medium'], null)),
        RefreshScope::all(),
        PricingSource::ModelsDev,
    );

    expect($outcome)->toBe(WriteOutcome::Unchanged)
        ->and(AiModelPrice::query()->where('model', 'gpt-cap-update')->value('supports_reasoning'))->toBeTrue();
});

test('the writer leaves a locked row alone', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai', 'model' => 'gpt-cap-locked',
        'input_per_mtok' => '1.25', 'output_per_mtok' => '10',
        'is_price_locked' => true, 'supports_reasoning' => false,
    ]);

    resolve(AiModelPriceWriter::class)->write(
        capabilityCandidate('gpt-cap-locked', new ReasoningCapability(true, null, null)),
        RefreshScope::all(),
        PricingSource::ModelsDev,
    );

    expect(AiModelPrice::query()->where('model', 'gpt-cap-locked')->value('supports_reasoning'))->toBeFalse();
});

test('the models.dev adapter attaches the capability to its candidate', function (): void {
    $decoded = [
        'openai' => [
            'id' => 'openai',
            'name' => 'OpenAI',
            'models' => [
                'gpt-cap-feed' => [
                    'id' => 'gpt-cap-feed',
                    'name' => 'gpt-cap-feed',
                    'cost' => ['input' => 1, 'output' => 2],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                    'reasoning' => true,
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
                ],
            ],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = collect($results['openai']->candidates)->firstWhere('model', 'gpt-cap-feed');

    expect($candidate?->reasoning?->levels)->toBe(['low', 'high']);
});

test('the openrouter adapter attaches the capability to its candidates', function (): void {
    $models = json_decode((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')), true)['data'];
    $models = array_map(
        static fn (mixed $model): mixed => is_array($model) && ($model['id'] ?? null) === 'anthropic/claude-opus-5.5' ? [...$model, 'supported_parameters' => ['reasoning']] : $model,
        $models,
    );

    $result = new OpenRouterPricingAdapter()->adapt($models, RefreshScope::all());
    $candidate = collect($result?->candidates)->firstWhere('model', 'anthropic/claude-opus-5.5');

    expect($candidate?->reasoning?->supported)->toBeTrue();
});

test('the litellm adapter attaches the capability to its candidates', function (): void {
    $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/LiteLlm/prices.json')), true);
    $key = collect(array_keys($decoded))->first(fn (string $key): bool => str_ends_with($key, 'claude-opus-5-5'));
    $decoded[$key] = [...$decoded[$key], 'supports_reasoning' => true, 'reasoning_effort_levels' => ['low', 'medium']];

    $results = new LiteLlmPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = collect($results['anthropic']->candidates)->firstWhere('model', 'claude-opus-5-5');

    expect($candidate?->reasoning?->levels)->toBe(['low', 'medium']);
});

test('a price change and a capability change land together', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai', 'model' => 'gpt-cap-both',
        'input_per_mtok' => '1.2000', 'output_per_mtok' => '10',
        'supports_reasoning' => null,
    ]);

    $outcome = resolve(AiModelPriceWriter::class)->write(
        capabilityCandidate('gpt-cap-both', new ReasoningCapability(true, ['low'], null)),
        RefreshScope::all(),
        PricingSource::ModelsDev,
    );

    $row = AiModelPrice::query()->where('model', 'gpt-cap-both')->firstOrFail();

    expect($outcome)->toBe(WriteOutcome::Updated)
        ->and($row->input_per_mtok)->toBe('1.2500')
        ->and($row->supports_reasoning)->toBeTrue()
        ->and($row->reasoning_levels)->toBe(['low']);
});

test('a feed flipping a model to unsupported clears stale levels and style', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'openai', 'model' => 'gpt-cap-flip',
        'input_per_mtok' => '1.25', 'output_per_mtok' => '10',
        'supports_reasoning' => true, 'reasoning_levels' => ['low', 'high'], 'reasoning_style' => 'effort',
    ]);

    resolve(AiModelPriceWriter::class)->write(
        capabilityCandidate('gpt-cap-flip', new ReasoningCapability(false, null, null)),
        RefreshScope::all(),
        PricingSource::ModelsDev,
    );

    $row = AiModelPrice::query()->where('model', 'gpt-cap-flip')->firstOrFail();

    expect($row->supports_reasoning)->toBeFalse()
        ->and($row->reasoning_levels)->toBeNull()
        ->and($row->reasoning_style)->toBeNull();
});
