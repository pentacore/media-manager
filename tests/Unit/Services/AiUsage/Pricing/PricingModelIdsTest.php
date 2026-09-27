<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\PricingModelIds;

test('normalize trims usable identifiers', function (): void {
    expect(PricingModelIds::normalize('  gpt-5  '))->toBe('gpt-5')
        ->and(PricingModelIds::normalize('anthropic/claude-opus-5.5'))->toBe('anthropic/claude-opus-5.5');
});

test('normalize rejects empty, control-character and oversized identifiers', function (string $identifier): void {
    expect(PricingModelIds::normalize($identifier))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'control character' => ["gpt\n5"],
    'too long' => [str_repeat('a', 256)],
]);

test('dated snapshots are detected only when their base model is present', function (string $modelId, array $sliceIds, bool $expected): void {
    expect(PricingModelIds::isDatedVariantOfKnownBase($modelId, $sliceIds))->toBe($expected);
})->with([
    'compact date with base' => ['claude-haiku-4-5-20251001', ['claude-haiku-4-5' => true], true],
    'dashed date with base' => ['claude-haiku-4-5-2025-10-01', ['claude-haiku-4-5' => true], true],
    'no base sibling' => ['claude-haiku-4-5-20251001', [], false],
    'short numeric suffix' => ['grok-4-0709', ['grok-4' => true], false],
    'impossible date' => ['model-20251345', ['model' => true], false],
]);
