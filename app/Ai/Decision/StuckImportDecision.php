<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\StuckImportChoice;

/**
 * The classifier's call on one stuck import, with the inspection it read.
 */
final readonly class StuckImportDecision
{
    /**
     * @param  array<string, mixed>  $inspection
     */
    public function __construct(
        public StuckImportChoice $choice,
        public float $probability,
        public bool $isConfident,
        public bool $blocklist,
        public bool $searchReplacement,
        public ?float $blocklistProbability,
        public ?float $searchReplacementProbability,
        public array $inspection,
    ) {}

    /**
     * The short reason shown to a removal's human approver.
     */
    public function reason(): string
    {
        return sprintf('Classifier: %s, %d%% likely.', $this->choice->label(), (int) round($this->probability * 100));
    }

    /**
     * @return array{choice: string, probability: float, is_confident: bool, blocklist: bool, search_replacement: bool, blocklist_probability: float|null, search_replacement_probability: float|null}
     */
    public function toArray(): array
    {
        return [
            'choice' => $this->choice->value,
            'probability' => $this->probability,
            'is_confident' => $this->isConfident,
            'blocklist' => $this->blocklist,
            'search_replacement' => $this->searchReplacement,
            'blocklist_probability' => $this->blocklistProbability,
            'search_replacement_probability' => $this->searchReplacementProbability,
        ];
    }
}
