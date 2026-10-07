<?php

declare(strict_types=1);

use App\Ai\ReasoningOptions;
use App\Enums\AiReasoningLevel;

test('clamping picks the nearest accepted level and prefers the lower one on a tie', function (AiReasoningLevel $level, ?array $levels, ?AiReasoningLevel $expected): void {
    $accepted = $levels === null ? null : array_map(AiReasoningLevel::from(...), $levels);

    expect(ReasoningOptions::clamp($level, $accepted))->toBe($expected);
})->with([
    'unknown levels pass through' => [AiReasoningLevel::Max, null, AiReasoningLevel::Max],
    'no accepted levels sends nothing' => [AiReasoningLevel::High, [], null],
    'supported level kept' => [AiReasoningLevel::Medium, ['low', 'medium', 'high'], AiReasoningLevel::Medium],
    'none falls to the lowest level' => [AiReasoningLevel::None, ['low', 'medium', 'high'], AiReasoningLevel::Low],
    'xhigh on opus 4.6 becomes high' => [AiReasoningLevel::XHigh, ['low', 'medium', 'high', 'max'], AiReasoningLevel::High],
    'medium between low and high prefers low' => [AiReasoningLevel::Medium, ['low', 'high'], AiReasoningLevel::Low],
    'max above the ceiling becomes the ceiling' => [AiReasoningLevel::Max, ['low', 'high'], AiReasoningLevel::High],
]);

test('provider default and unsupported models send nothing', function (): void {
    expect(ReasoningOptions::fragment('openai', 'gpt-5', AiReasoningLevel::ProviderDefault, null, null, null))->toBe([])
        ->and(ReasoningOptions::fragment('openai', 'gpt-4.1', AiReasoningLevel::High, false, null, null))->toBe([])
        ->and(ReasoningOptions::fragment('groq', 'llama', AiReasoningLevel::High, null, null, null))->toBe([]);
});

test('provider fragments', function (string $provider, ?string $model, AiReasoningLevel $level, ?array $levels, ?string $style, array $expected): void {
    $accepted = $levels === null ? null : array_map(AiReasoningLevel::from(...), $levels);

    expect(ReasoningOptions::fragment($provider, $model, $level, null, $accepted, $style))->toBe($expected);
})->with([
    'openai effort as is' => ['openai', 'gpt-5.6-luna', AiReasoningLevel::Max, null, null, ['reasoning' => ['effort' => 'max']]],
    'openai none' => ['openai', 'gpt-5.6-luna', AiReasoningLevel::None, null, null, ['reasoning' => ['effort' => 'none']]],
    'openai clamps none on astra' => ['openai', 'gpt-6-astra', AiReasoningLevel::None, ['low', 'medium', 'high', 'xhigh', 'max'], null, ['reasoning' => ['effort' => 'low']]],
    'azure like openai' => ['azure', 'gpt-5', AiReasoningLevel::High, null, null, ['reasoning' => ['effort' => 'high']]],
    'openrouter caps max at xhigh' => ['openrouter', 'openai/gpt-5', AiReasoningLevel::Max, null, null, ['reasoning' => ['effort' => 'xhigh']]],
    'xai caps unknown max at xhigh' => ['xai', 'grok-4.7', AiReasoningLevel::Max, null, null, ['reasoning' => ['effort' => 'xhigh']]],
    'gemini none becomes low' => ['gemini', 'gemini-3.5-flash', AiReasoningLevel::None, null, null, ['thinking_level' => 'low']],
    'gemini max becomes high' => ['gemini', 'gemini-3.1-pro-preview', AiReasoningLevel::Max, null, null, ['thinking_level' => 'high']],
    'anthropic budget model' => ['anthropic', 'claude-haiku-4-5', AiReasoningLevel::Medium, null, null, ['thinking' => ['type' => 'enabled', 'budget_tokens' => 8192]]],
    'anthropic budget none' => ['anthropic', 'claude-haiku-4-5', AiReasoningLevel::None, null, null, []],
    'anthropic opus 5.5 none floors at low' => ['anthropic', 'claude-opus-5-5', AiReasoningLevel::None, null, null, ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']]],
    'anthropic sonnet 5.5 none' => ['anthropic', 'claude-sonnet-5-5', AiReasoningLevel::None, null, null, ['thinking' => ['type' => 'between_tools']]],
    'anthropic opus 5 none' => ['anthropic', 'claude-opus-5', AiReasoningLevel::None, null, null, ['thinking' => ['type' => 'disabled']]],
    'anthropic adaptive effort' => ['anthropic', 'claude-opus-5', AiReasoningLevel::XHigh, null, null, ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'xhigh']]],
    'anthropic 4.6 has no xhigh' => ['anthropic', 'claude-sonnet-4-6', AiReasoningLevel::XHigh, null, null, ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'high']]],
    'anthropic feed style wins' => ['anthropic', 'claude-new-model', AiReasoningLevel::Low, null, 'budget_tokens', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]]],
]);

test('anthropic budgets stay below the request max tokens', function (): void {
    expect(ReasoningOptions::fragment('anthropic', 'claude-haiku-4-5', AiReasoningLevel::High, null, null, null, maxTokens: 4096))
        ->toBe(['thinking' => ['type' => 'enabled', 'budget_tokens' => 3072]])
        ->and(ReasoningOptions::fragment('anthropic', 'claude-haiku-4-5', AiReasoningLevel::High, null, null, null, maxTokens: 1500))
        ->toBe([]);
});

test('chat asks openai for a reasoning summary', function (): void {
    expect(ReasoningOptions::fragment('openai', 'gpt-5', AiReasoningLevel::Low, null, null, null, summarize: true))
        ->toBe(['reasoning' => ['effort' => 'low', 'summary' => 'auto']]);
});
