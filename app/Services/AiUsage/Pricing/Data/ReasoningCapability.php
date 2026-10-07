<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Enums\AiReasoningLevel;

/**
 * What a pricing feed says about a model's reasoning support: whether it
 * reasons, which of our levels it accepts and (Anthropic) which request
 * style it takes. Null fields are unknown.
 */
final readonly class ReasoningCapability
{
    public const string STYLE_EFFORT = 'effort';

    public const string STYLE_BUDGET = 'budget_tokens';

    /**
     * @param  list<string>|null  $levels
     */
    public function __construct(
        public ?bool $supported,
        public ?array $levels,
        public ?string $style,
    ) {}

    /**
     * @param  array<string, mixed>  $modelData
     */
    public static function fromModelsDev(array $modelData): ?self
    {
        if (! array_key_exists('reasoning', $modelData)) {
            return null;
        }

        if ($modelData['reasoning'] !== true) {
            return new self(false, null, null);
        }

        $options = is_array($modelData['reasoning_options'] ?? null) ? $modelData['reasoning_options'] : [];
        $types = array_map(static fn (mixed $option): ?string => is_array($option) ? ($option['type'] ?? null) : null, $options);

        $effortValues = null;

        foreach ($options as $option) {
            if (is_array($option) && ($option['type'] ?? null) === 'effort' && is_array($option['values'] ?? null)) {
                $effortValues = $option['values'];
            }
        }

        $levels = $effortValues === null ? null : self::knownLevels([
            ...(in_array('toggle', $types, true) ? ['none'] : []),
            ...$effortValues,
        ]);

        $style = match (true) {
            in_array('effort', $types, true) => self::STYLE_EFFORT,
            in_array('budget_tokens', $types, true) => self::STYLE_BUDGET,
            default => null,
        };

        return new self(true, $levels, $style);
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    public static function fromOpenRouter(array $modelData): ?self
    {
        $parameters = $modelData['supported_parameters'] ?? null;

        if (! is_array($parameters)) {
            return null;
        }

        return new self(in_array('reasoning', $parameters, true), null, null);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromLiteLlm(array $entry): ?self
    {
        $supported = is_bool($entry['supports_reasoning'] ?? null) ? $entry['supports_reasoning'] : null;
        $levels = is_array($entry['reasoning_effort_levels'] ?? null)
            ? self::knownLevels([
                ...(($entry['supports_none_reasoning_effort'] ?? false) === true ? ['none'] : []),
                ...$entry['reasoning_effort_levels'],
            ])
            : null;

        return $supported === null && $levels === null ? null : new self($supported, $levels, null);
    }

    public static function preferring(?self $first, ?self $second): ?self
    {
        if (! $first instanceof self || ! $second instanceof self) {
            return $first ?? $second;
        }

        return new self(
            $first->supported ?? $second->supported,
            $first->levels ?? $second->levels,
            $first->style ?? $second->style,
        );
    }

    /**
     * The ai_model_prices columns this capability knows.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return array_filter([
            'supports_reasoning' => $this->supported,
            'reasoning_levels' => $this->levels,
            'reasoning_style' => $this->style,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Our sendable level values, in scale order, that appear in the feed list.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function knownLevels(array $values): array
    {
        $strings = array_filter($values, is_string(...));

        return array_values(array_map(
            static fn (AiReasoningLevel $aiReasoningLevel): string => $aiReasoningLevel->value,
            array_filter(AiReasoningLevel::sendable(), static fn (AiReasoningLevel $aiReasoningLevel): bool => in_array($aiReasoningLevel->value, $strings, true)),
        ));
    }
}
