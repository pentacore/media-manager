<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;

/**
 * How the second opinion runs for one proposed action: the mode (the event's
 * override, else the action type's rule), the engine and Jev threshold (the
 * event's, else the Decision Agent defaults) and the event whose verifier
 * model tiers apply. `$overridden` is true when a field of the event's
 * override took effect; `$skipsActionRule` is true when the event's Off
 * skipped a check the action type's rule would run.
 */
final readonly class SecondOpinionPolicy
{
    public function __construct(
        public SecondOpinionMode $mode,
        public SecondOpinionEngine $engine,
        public float $jevThreshold,
        public ?string $eventKey = null,
        public bool $overridden = false,
        public bool $skipsActionRule = false,
    ) {}

    /**
     * Whether a stored action_requests.second_opinion is a skip marker
     * (see skipMarker()) rather than a verdict.
     *
     * @param  array<string, mixed>|null  $secondOpinion
     */
    public static function isSkipMarker(?array $secondOpinion): bool
    {
        return ($secondOpinion['skipped'] ?? false) === true;
    }

    /**
     * The event recorded on the stored verdict: only when an override shaped the check.
     */
    public function overrideEventKey(): ?string
    {
        return $this->overridden ? $this->eventKey : null;
    }

    /**
     * What action_requests.second_opinion stores when the event's Off
     * skipped the check, so the card can say a per-event rule decided it.
     * Null when no check was skipped.
     *
     * @return array{skipped: true, mode: string, event_key: string}|null
     */
    public function skipMarker(): ?array
    {
        $eventKey = $this->overrideEventKey();

        if (! $this->skipsActionRule || $eventKey === null) {
            return null;
        }

        return ['skipped' => true, 'mode' => SecondOpinionMode::Off->value, 'event_key' => $eventKey];
    }
}
