<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;
use App\Models\AiModelPrice;

/**
 * Translates a resolved reasoning level into the request fragment each
 * provider expects, clamped to the levels the model accepts (ai_model_prices
 * capability columns). Unknown capabilities send the level as is.
 */
final class ReasoningOptions
{
    private const array PROVIDERS = ['openai', 'azure', 'openrouter', 'anthropic', 'gemini', 'xai'];

    /** @var array<string, AiModelPrice|null> */
    private array $prices = [];

    /**
     * @return array<string, mixed>
     */
    public function for(string $provider, ?string $model, AiReasoningLevel $aiReasoningLevel, bool $summarize = false, ?int $maxTokens = null): array
    {
        $price = $model === null ? null : $this->price($provider, $model);

        return self::fragment(
            $provider,
            $model,
            $aiReasoningLevel,
            $price?->supports_reasoning,
            $price?->acceptedReasoningLevels(),
            $price?->reasoning_style,
            $summarize,
            $maxTokens,
        );
    }

    public static function supportsProvider(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    /**
     * @param  list<AiReasoningLevel>|null  $levels
     * @return array<string, mixed>
     */
    public static function fragment(
        string $provider,
        ?string $model,
        AiReasoningLevel $aiReasoningLevel,
        ?bool $supported,
        ?array $levels,
        ?string $style,
        bool $summarize = false,
        ?int $maxTokens = null,
    ): array {
        if (! $aiReasoningLevel->isSendable() || $supported === false || ! self::supportsProvider($provider)) {
            return [];
        }

        $level = self::clamp($aiReasoningLevel, $levels);

        if (! $level instanceof AiReasoningLevel) {
            return [];
        }

        return match ($provider) {
            'openai', 'azure' => ['reasoning' => array_filter([
                'effort' => $level->value,
                'summary' => $summarize && $level !== AiReasoningLevel::None ? 'auto' : null,
            ])],
            'openrouter', 'xai' => ['reasoning' => ['effort' => $level === AiReasoningLevel::Max ? AiReasoningLevel::XHigh->value : $level->value]],
            'gemini' => ['thinking_level' => match ($level) {
                AiReasoningLevel::None => 'low',
                AiReasoningLevel::XHigh, AiReasoningLevel::Max => 'high',
                default => $level->value,
            }],
            'anthropic' => AnthropicReasoningFamily::for($model, $style)->fragment($level, $maxTokens),
            default => [],
        };
    }

    /**
     * The level to send: as is when the accepted levels are unknown or
     * include it; otherwise the nearest accepted level (the lower one on a
     * tie). `none` without a `none` option falls to the lowest. Null = none
     * accepted, send nothing.
     *
     * @param  list<AiReasoningLevel>|null  $levels
     */
    public static function clamp(AiReasoningLevel $aiReasoningLevel, ?array $levels): ?AiReasoningLevel
    {
        if ($levels === null || in_array($aiReasoningLevel, $levels, true)) {
            return $aiReasoningLevel;
        }

        if ($levels === []) {
            return null;
        }

        usort($levels, static fn (AiReasoningLevel $a, AiReasoningLevel $b): int => $a->rank() <=> $b->rank());

        if ($aiReasoningLevel === AiReasoningLevel::None) {
            return $levels[0];
        }

        $best = $levels[0];

        foreach ($levels as $candidate) {
            if (abs($candidate->rank() - $aiReasoningLevel->rank()) < abs($best->rank() - $aiReasoningLevel->rank())) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function price(string $provider, string $model): ?AiModelPrice
    {
        $key = sprintf('%s|%s', $provider, $model);

        if (! array_key_exists($key, $this->prices)) {
            $this->prices[$key] = AiModelPrice::query()->where('provider', $provider)->where('model', $model)->first();
        }

        return $this->prices[$key];
    }
}
