<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;
use App\Services\AiUsage\Pricing\Data\ReasoningCapability;
use Illuminate\Support\Str;

/**
 * How a Claude model takes reasoning (verified against the Anthropic docs,
 * 2026-10-07): older models take a thinking budget; 4.6+ take adaptive
 * thinking plus `output_config.effort`; Opus 5.5 / Fable can't turn thinking
 * off and Sonnet 5.5 switches it off with `between_tools`.
 */
enum AnthropicReasoningFamily
{
    case Budget;
    case AlwaysOn;
    case BetweenTools;
    case Adaptive;
    case AdaptiveWithoutXHigh;

    /** Budget per level, kept below the request's max_tokens. */
    private const array BUDGETS = ['low' => 2048, 'medium' => 8192, 'high' => 16384, 'xhigh' => 32768, 'max' => 49152];

    private const int SDK_MAX_TOKENS = 64000;

    private const int MIN_BUDGET = 1024;

    public static function for(?string $model, ?string $style): self
    {
        $model = (string) $model;

        return match (true) {
            $style === ReasoningCapability::STYLE_BUDGET,
            Str::startsWith($model, ['claude-3', 'claude-haiku-4-5', 'claude-sonnet-4-5', 'claude-opus-4-5', 'claude-opus-4-1', 'claude-opus-4-0', 'claude-opus-4-2', 'claude-sonnet-4-0', 'claude-sonnet-4-2']) => self::Budget,
            Str::startsWith($model, ['claude-opus-5-5', 'claude-fable-5']) => self::AlwaysOn,
            Str::startsWith($model, 'claude-sonnet-5-5') => self::BetweenTools,
            Str::startsWith($model, ['claude-opus-4-6', 'claude-sonnet-4-6']) => self::AdaptiveWithoutXHigh,
            default => self::Adaptive,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function fragment(AiReasoningLevel $aiReasoningLevel, ?int $maxTokens): array
    {
        if ($this === self::Budget) {
            if ($aiReasoningLevel === AiReasoningLevel::None) {
                return [];
            }

            $budget = min(self::BUDGETS[$aiReasoningLevel->value], ($maxTokens ?? self::SDK_MAX_TOKENS) - self::MIN_BUDGET);

            return $budget < self::MIN_BUDGET ? [] : ['thinking' => ['type' => 'enabled', 'budget_tokens' => $budget]];
        }

        if ($aiReasoningLevel === AiReasoningLevel::None) {
            return match ($this) {
                self::AlwaysOn => ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']],
                self::BetweenTools => ['thinking' => ['type' => 'between_tools']],
                default => ['thinking' => ['type' => 'disabled']],
            };
        }

        $effort = $this === self::AdaptiveWithoutXHigh && $aiReasoningLevel === AiReasoningLevel::XHigh
            ? AiReasoningLevel::High
            : $aiReasoningLevel;

        return ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => $effort->value]];
    }
}
