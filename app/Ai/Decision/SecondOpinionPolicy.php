<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;

/**
 * How the second opinion runs for one proposed action: the mode (the event's
 * override, else the action type's rule), the engine and Jev threshold (the
 * event's, else the Decision Agent defaults) and the event whose verifier
 * model tiers apply. `$overridden` is true when the event has a per-event
 * entry or verifier tiers of its own.
 */
final readonly class SecondOpinionPolicy
{
    public function __construct(
        public SecondOpinionMode $mode,
        public SecondOpinionEngine $engine,
        public float $jevThreshold,
        public ?string $eventKey = null,
        public bool $overridden = false,
    ) {}

    /**
     * The event recorded on the stored verdict: only when an override shaped the check.
     */
    public function overrideEventKey(): ?string
    {
        return $this->overridden ? $this->eventKey : null;
    }
}
