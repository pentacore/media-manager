<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\ModelsDevPricingAdapter;
use App\Services\AiUsage\Pricing\RefreshScope;

/**
 * Decode the shared Models.dev fixture as an associative provider map.
 *
 * @return array<string, mixed>
 */
function modelsDevDecoded(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/ModelsDev/api.json')),
        true,
    );

    return $decoded;
}

/**
 * Build a decoded single-provider catalog around one model definition.
 *
 * @param  array<string, mixed>  $model
 * @return array<string, mixed>
 */
function modelsDevProvider(string $provider, string $modelId, array $model): array
{
    return [
        $provider => [
            'id' => $provider,
            'name' => ucfirst($provider),
            'models' => [
                $modelId => ['id' => $modelId, 'name' => $modelId, ...$model],
            ],
        ],
    ];
}

/**
 * Find the rejection for a given model id inside a provider result.
 */
function rejectionFor(ProviderPricingResult $providerPricingResult, string $modelId): ?PricingRejection
{
    foreach ($providerPricingResult->rejections as $rejection) {
        if ($rejection->model === $modelId) {
            return $rejection;
        }
    }

    return null;
}

/**
 * Collect the model ids of every candidate in a provider result.
 *
 * @return list<string>
 */
function candidateModels(ProviderPricingResult $providerPricingResult): array
{
    return array_map(fn (ModelPriceCandidate $modelPriceCandidate): string => $modelPriceCandidate->model, $providerPricingResult->candidates);
}

test('adapts every supported provider and maps google to the gemini driver', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect($results)->toHaveKeys(['openai', 'anthropic', 'gemini', 'openrouter'])
        ->and($results)->not->toHaveKey('google')
        ->and($results['gemini'])->toBeInstanceOf(ProviderPricingResult::class)
        ->and($results['gemini']->provider)->toBe('gemini');
});

test('ignores providers without a canonical mapping such as vertex', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect($results)->not->toHaveKey('vertex');
});

test('honors provider scope by omitting providers outside the allowlist', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::forProviders(['openai']));

    expect(array_keys($results))->toBe(['openai']);
});

test('accepts a text output model with a multimodal input', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect(candidateModels($results['openai']))->toContain('gpt-4o');

    $candidate = collect($results['openai']->candidates)->firstWhere('model', 'gpt-4o');

    expect($candidate->fields['input_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['input_per_mtok']->value)->toBe('2.5')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('10');
});

test('accepts a fully multimodal input including audio and video', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect(candidateModels($results['gemini']))->toContain('gemini-1.5-pro');
});

test('rejects a non-text output model and keeps it out of candidates', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect(candidateModels($results['openai']))->not->toContain('text-embedding-3-small');

    $rejection = rejectionFor($results['openai'], 'text-embedding-3-small');

    expect($rejection)->not->toBeNull()
        ->and($rejection->code)->toBe(PricingRejection::NON_TEXT_OUTPUT)
        ->and($rejection->detail)->toBe('declared_non_text');
});

test('rejects a model with no declared modalities as a metadata gap', function (): void {
    $decoded = modelsDevProvider('openai', 'no-modalities', [
        'cost' => ['input' => 1, 'output' => 2],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty();

    $rejection = rejectionFor($results['openai'], 'no-modalities');

    expect($rejection)->not->toBeNull()
        ->and($rejection->code)->toBe(PricingRejection::NON_TEXT_OUTPUT)
        ->and($rejection->detail)->toBe('missing_modalities');
});

test('rejects a model whose modalities omit the output list as a metadata gap', function (): void {
    $decoded = modelsDevProvider('openai', 'input-only-modalities', [
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty();

    $rejection = rejectionFor($results['openai'], 'input-only-modalities');

    expect($rejection)->not->toBeNull()
        ->and($rejection->code)->toBe(PricingRejection::NON_TEXT_OUTPUT)
        ->and($rejection->detail)->toBe('missing_modalities');
});

test('rejects a model whose output modality list is not an array as a metadata gap', function (): void {
    $decoded = modelsDevProvider('openai', 'malformed-output-modalities', [
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => 'text'],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty();

    $rejection = rejectionFor($results['openai'], 'malformed-output-modalities');

    expect($rejection)->not->toBeNull()
        ->and($rejection->code)->toBe(PricingRejection::NON_TEXT_OUTPUT)
        ->and($rejection->detail)->toBe('missing_modalities');
});

test('skips a deprecated model expressed with the fixture boolean flag', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect(candidateModels($results['anthropic']))->not->toContain('claude-2.0');

    $rejection = rejectionFor($results['anthropic'], 'claude-2.0');

    expect($rejection)->not->toBeNull()
        ->and($rejection->code)->toBe(PricingRejection::DEPRECATED);
});

test('skips a deprecated model expressed with the models.dev status string', function (): void {
    $decoded = modelsDevProvider('openai', 'gpt-legacy', [
        'status' => 'deprecated',
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and(rejectionFor($results['openai'], 'gpt-legacy')?->code)->toBe(PricingRejection::DEPRECATED);
});

test('accepts a preview model', function (): void {
    $decoded = modelsDevProvider('openai', 'gpt-preview', [
        'status' => 'preview',
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toContain('gpt-preview');
});

test('rejects a model with no cost object', function (): void {
    $decoded = modelsDevProvider('openai', 'no-cost', [
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'no-cost')?->code)->toBe(PricingRejection::MISSING_COST);
});

test('rejects a model missing the input cost', function (): void {
    $decoded = modelsDevProvider('openai', 'no-input', [
        'cost' => ['output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'no-input')?->code)->toBe(PricingRejection::MISSING_INPUT);
});

test('rejects a model missing the output cost', function (): void {
    $decoded = modelsDevProvider('openai', 'no-output', [
        'cost' => ['input' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'no-output')?->code)->toBe(PricingRejection::MISSING_OUTPUT);
});

test('retains the presence of supplied cache and reasoning fields', function (): void {
    $decoded = modelsDevProvider('anthropic', 'full-cache', [
        'cost' => [
            'input' => 3,
            'output' => 15,
            'cache_read' => 0.3,
            'cache_write' => 3.75,
            'reasoning' => 6,
        ],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['anthropic']->candidates[0];

    expect($candidate->fields['cache_read_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0.3')
        ->and($candidate->fields['cache_write_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['reasoning_per_mtok']->value)->toBe('6');
});

test('marks absent optional fields as missing rather than zero', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());
    $candidate = collect($results['openai']->candidates)->firstWhere('model', 'gpt-4o-mini');

    expect($candidate->fields['cache_read_per_mtok']->supplied)->toBeFalse()
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBeNull()
        ->and($candidate->fields['cache_write_per_mtok']->supplied)->toBeFalse()
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeFalse();
});

test('retains an explicit zero price as supplied', function (): void {
    $decoded = modelsDevProvider('openai', 'zero-cache', [
        'cost' => ['input' => 1, 'output' => 2, 'cache_read' => 0],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['openai']->candidates[0];

    expect($candidate->fields['cache_read_per_mtok']->supplied)->toBeTrue()
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0');
});

test('accepts numeric integer, float, and string decimal costs', function (): void {
    $decoded = modelsDevProvider('openai', 'mixed-types', [
        'cost' => ['input' => '1.25', 'output' => 10, 'cache_read' => 0.5],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['openai']->candidates[0];

    expect($candidate->fields['input_per_mtok']->value)->toBe('1.25')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('10')
        ->and($candidate->fields['cache_read_per_mtok']->value)->toBe('0.5');
});

test('detects a context tier array without flattening the base cost', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());
    $candidate = collect($results['gemini']->candidates)->firstWhere('model', 'gemini-1.5-pro');

    expect($candidate->tiered)->toBeTrue()
        ->and($candidate->fields['input_per_mtok']->value)->toBe('1.25')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('5');

    $warning = collect($results['gemini']->warnings)->firstWhere('model', 'gemini-1.5-pro');

    expect($warning)->toBeInstanceOf(PricingWarning::class)
        ->and($warning->code)->toBe(PricingWarning::CONTEXT_TIERS);
});

test('detects a context_over threshold key as a tier signal', function (): void {
    $decoded = modelsDevProvider('xai', 'grok-tier', [
        'cost' => [
            'input' => 1.25,
            'output' => 2.5,
            'context_over_200k' => ['input' => 2.5, 'output' => 5],
        ],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['xai']->candidates[0];

    expect($candidate->tiered)->toBeTrue()
        ->and($candidate->fields['input_per_mtok']->value)->toBe('1.25')
        ->and($candidate->fields['output_per_mtok']->value)->toBe('2.5')
        ->and(collect($results['xai']->warnings)->firstWhere('model', 'grok-tier')?->code)
        ->toBe(PricingWarning::CONTEXT_TIERS);
});

test('ignores an audio input modality and audio cost keys', function (): void {
    $decoded = modelsDevProvider('openai', 'audio-model', [
        'cost' => ['input' => 1, 'output' => 2, 'audio' => 5, 'input_audio' => 8],
        'modalities' => ['input' => ['text', 'audio'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['openai']->candidates[0];

    expect(candidateModels($results['openai']))->toContain('audio-model')
        ->and($candidate->tiered)->toBeFalse()
        ->and($candidate->fields)->not->toHaveKey('audio')
        ->and($candidate->fields['reasoning_per_mtok']->supplied)->toBeFalse();
});

test('rejects a negative cost', function (): void {
    $decoded = modelsDevProvider('openai', 'negative', [
        'cost' => ['input' => -1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'negative')?->code)->toBe(PricingRejection::INVALID_COST);
});

test('rejects an out-of-range cost', function (): void {
    $decoded = modelsDevProvider('openai', 'huge', [
        'cost' => ['input' => 100000, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'huge')?->code)->toBe(PricingRejection::INVALID_COST);
});

test('rejects a non-finite or non-numeric cost string', function (): void {
    $decoded = modelsDevProvider('openai', 'nan-cost', [
        'cost' => ['input' => 'NaN', 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(rejectionFor($results['openai'], 'nan-cost')?->code)->toBe(PricingRejection::INVALID_COST);
});

test('rejects a model with an empty identifier', function (): void {
    $decoded = [
        'openai' => [
            'id' => 'openai',
            'models' => [
                '' => ['id' => '', 'cost' => ['input' => 1, 'output' => 2]],
            ],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::INVALID_IDENTIFIER);
});

test('represents openrouter results as create suppressed while other providers are not', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect($results['openrouter']->createSuppressed)->toBeTrue()
        ->and(candidateModels($results['openrouter']))->toContain('openai/gpt-4o')
        ->and($results['openai']->createSuppressed)->toBeFalse();
});

test('preserves the exact upstream model identifier including slashes', function (): void {
    $results = new ModelsDevPricingAdapter()->adapt(modelsDevDecoded(), RefreshScope::all());

    expect(candidateModels($results['openrouter']))->toBe(['openai/gpt-4o']);
});

test('marks a provider with a malformed models collection', function (): void {
    $decoded = ['openai' => ['id' => 'openai', 'models' => 'not-an-object']];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::MALFORMED_PROVIDER);
});

test('stamps provenance source url and a valid update date from the model metadata', function (): void {
    $decoded = modelsDevProvider('openai', 'dated', [
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
        'last_updated' => '2025-01-15',
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());
    $candidate = $results['openai']->candidates[0];

    expect($candidate->sourceUrl)->toBe(config('mediamanager.ai.pricing.models_dev.url'))
        ->and($candidate->sourceUpdatedAt)->toBe('2025-01-15');
});

test('merges results when configured upstream providers share a canonical provider', function (): void {
    config(['mediamanager.ai.pricing.providers' => [
        ...config('mediamanager.ai.pricing.providers'),
        'openai-compatible' => 'openai',
    ]]);

    $decoded = [
        ...modelsDevProvider('openai', 'gpt-primary', [
            'cost' => ['input' => 1, 'output' => 2],
            'modalities' => ['input' => ['text'], 'output' => ['text']],
        ]),
        ...modelsDevProvider('openai-compatible', 'gpt-compatible', [
            'cost' => ['input' => 3, 'output' => 4, 'tiers' => [['threshold' => 1000]]],
            'modalities' => ['input' => ['text'], 'output' => ['text']],
        ]),
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(array_keys($results))->toBe(['openai'])
        ->and(candidateModels($results['openai']))->toBe(['gpt-primary', 'gpt-compatible'])
        ->and($results['openai']->warnings)->toHaveCount(1)
        ->and($results['openai']->warnings[0]->model)->toBe('gpt-compatible')
        ->and($results['openai']->createSuppressed)->toBeFalse();
});

test('preserves create suppression when merged canonical provider results disagree', function (): void {
    config(['mediamanager.ai.pricing.providers' => [
        ...config('mediamanager.ai.pricing.providers'),
        'openrouter-compatible' => 'openrouter',
    ]]);

    $decoded = [
        'openrouter' => ['id' => 'openrouter', 'models' => 'malformed'],
        ...modelsDevProvider('openrouter-compatible', 'provider/model', [
            'cost' => ['input' => 1, 'output' => 2],
            'modalities' => ['input' => ['text'], 'output' => ['text']],
        ]),
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect($results['openrouter']->createSuppressed)->toBeTrue()
        ->and($results['openrouter']->candidates)->toHaveCount(1)
        ->and($results['openrouter']->rejections)->toHaveCount(1);
});

test('drops invalid or nonexistent source update dates', function (mixed $lastUpdated): void {
    $decoded = modelsDevProvider('openai', 'dated', [
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
        'last_updated' => $lastUpdated,
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect($results['openai']->candidates[0]->sourceUpdatedAt)->toBeNull();
})->with([
    'year zero' => '0000-01-01',
    'nonexistent leap day' => '2025-02-29',
    'invalid month' => '2025-13-01',
    'not zero padded' => '2025-1-01',
    'timestamp instead of date' => '2025-01-15T12:00:00Z',
    'non-string' => 20250115,
]);

test('trims surrounding whitespace from a model identifier', function (): void {
    $decoded = modelsDevProvider('openai', 'map-key', [
        'id' => '  gpt-whitespace  ',
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBe(['gpt-whitespace']);
});

test('rejects a model identifier containing control characters', function (): void {
    $decoded = modelsDevProvider('openai', 'map-key', [
        'id' => "gpt-\x00control",
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::INVALID_IDENTIFIER);
});

test('rejects a model identifier longer than the database string limit', function (): void {
    $oversizedId = str_repeat('a', 256);
    $decoded = modelsDevProvider('openai', 'map-key', [
        'id' => $oversizedId,
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::INVALID_IDENTIFIER);
});

test('rejects a multibyte model identifier exceeding 255 bytes', function (): void {
    $oversizedId = str_repeat('é', 128);
    $decoded = modelsDevProvider('openai', 'map-key', [
        'id' => $oversizedId,
        'cost' => ['input' => 1, 'output' => 2],
        'modalities' => ['input' => ['text'], 'output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::INVALID_IDENTIFIER);
});

test('rejects a malformed non-object model entry', function (): void {
    $decoded = [
        'openai' => [
            'id' => 'openai',
            'models' => ['gpt-malformed' => 'not-an-object'],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->model)->toBe('gpt-malformed')
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::MALFORMED_MODEL);
});

test('skips a dated model variant when its base model is in the same slice', function (string $datedId, string $baseId): void {
    $decoded = [
        'anthropic' => [
            'id' => 'anthropic',
            'models' => [
                $baseId => ['id' => $baseId, 'cost' => ['input' => 1, 'output' => 5], 'modalities' => ['output' => ['text']]],
                $datedId => ['id' => $datedId, 'cost' => ['input' => 1, 'output' => 5], 'modalities' => ['output' => ['text']]],
            ],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['anthropic']))->toBe([$baseId])
        ->and(rejectionFor($results['anthropic'], $datedId)?->code)->toBe(PricingRejection::DATED_VARIANT);
})->with([
    'compact date suffix' => ['claude-haiku-4-5-20251001', 'claude-haiku-4-5'],
    'dashed date suffix' => ['claude-haiku-4-5-2025-10-01', 'claude-haiku-4-5'],
]);

test('keeps a dated model that has no base variant in the slice', function (): void {
    $decoded = modelsDevProvider('anthropic', 'claude-legacy-20240229', [
        'cost' => ['input' => 15, 'output' => 75],
        'modalities' => ['output' => ['text']],
    ]);

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['anthropic']))->toBe(['claude-legacy-20240229']);
});

test('does not mistake a short numeric suffix for a date', function (): void {
    $decoded = [
        'groq' => [
            'id' => 'groq',
            'models' => [
                'moonshotai/kimi-k2-instruct' => ['cost' => ['input' => 1, 'output' => 3], 'modalities' => ['output' => ['text']]],
                'moonshotai/kimi-k2-instruct-0905' => ['cost' => ['input' => 1, 'output' => 3], 'modalities' => ['output' => ['text']]],
            ],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['groq']))->toContain('moonshotai/kimi-k2-instruct-0905');
});

test('an ignored provider is excluded from adaptation entirely', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['groq']);

    $decoded = modelsDevProvider('groq', 'llama-3.1-8b-instant', [
        'cost' => ['input' => 0.05, 'output' => 0.08],
    ]);

    expect(new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all()))->toBe([]);
});

test('ignoring a provider accepts either the upstream or canonical spelling', function (): void {
    config()->set('mediamanager.ai.pricing.ignored_providers', ['google']);

    $decoded = modelsDevProvider('google', 'gemini-2.5-pro', [
        'cost' => ['input' => 1.25, 'output' => 10],
    ]);

    expect(new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all()))->toBe([])
        ->and(RefreshScope::canonicalProvider('gemini'))->toBeNull()
        ->and(RefreshScope::all()->allowsProvider('gemini'))->toBeFalse();
});

test('a list-shaped model collection is malformed, not a valid provider slice', function (): void {
    $decoded = [
        'openai' => [
            'id' => 'openai',
            'models' => [
                ['id' => 'gpt-listed', 'cost' => ['input' => 1, 'output' => 5], 'modalities' => ['output' => ['text']]],
            ],
        ],
    ];

    $results = new ModelsDevPricingAdapter()->adapt($decoded, RefreshScope::all());

    expect(candidateModels($results['openai']))->toBeEmpty()
        ->and($results['openai']->rejections[0]->code)->toBe(PricingRejection::MALFORMED_PROVIDER);
});
