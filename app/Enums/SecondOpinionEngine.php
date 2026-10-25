<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Which verifier gives the second opinion on a Decision agent proposal.
 */
enum SecondOpinionEngine: string
{
    use EnumUtils;

    case Llm = 'llm';
    case JevThenLlm = 'jev_then_llm';
    case Jev = 'jev';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM',
            self::JevThenLlm => 'Jev, then LLM on doubt',
            self::Jev => 'Jev decides',
        };
    }

    /**
     * Whether the engine asks the classification provider (Jev).
     */
    public function usesClassifier(): bool
    {
        return $this !== self::Llm;
    }

    /**
     * Whether the engine can ask the LLM verifier (always, or on doubt).
     */
    public function canAskLlm(): bool
    {
        return $this !== self::Jev;
    }
}
