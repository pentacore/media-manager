<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Models\AiModelPrice;
use Laravel\Ai\Tools\Request;

test('creates a new price row when (provider, model) does not exist', function (): void {
    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x-test')->count())->toBe(0);

    $result = json_decode((new UpsertModelPriceTool)->handle(new Request([
        'provider' => 'openai',
        'model' => 'gpt-x-test',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 5,
    ])), true);

    expect($result['upserted'])->toBeTrue();

    $row = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-x-test')->firstOrFail();
    expect((float) $row->input_per_mtok)->toBe(1.25)
        ->and((float) $row->output_per_mtok)->toBe(5.0);
});

test('updates the existing row in place rather than creating a duplicate', function (): void {
    AiModelPrice::factory()->create([
        'provider' => 'anthropic',
        'model' => 'claude-test',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
    ]);

    (new UpsertModelPriceTool)->handle(new Request([
        'provider' => 'anthropic',
        'model' => 'claude-test',
        'input_per_mtok' => 3.0,
        'output_per_mtok' => 15.0,
        'cache_read_per_mtok' => 0.3,
        'cache_write_per_mtok' => 3.75,
    ]));

    $rows = AiModelPrice::query()->where('provider', 'anthropic')->where('model', 'claude-test')->get();
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->input_per_mtok)->toBe(3.0)
        ->and((float) $rows[0]->cache_write_per_mtok)->toBe(3.75);
});

test('rejects an empty provider or model with invalid_args', function (): void {
    $result = json_decode((new UpsertModelPriceTool)->handle(new Request([
        'provider' => '',
        'model' => 'gpt-x',
        'input_per_mtok' => 1,
        'output_per_mtok' => 1,
    ])), true);

    expect($result['error'])->toBe('invalid_args');
});

test('risk is SafeWrite', function (): void {
    expect((new UpsertModelPriceTool)->risk())->toBe(Risk::SafeWrite);
});
