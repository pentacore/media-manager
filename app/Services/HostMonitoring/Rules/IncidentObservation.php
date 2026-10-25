<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring\Rules;

use App\Enums\IncidentSeverity;

/**
 * What one rule concluded about one incident key in one report: raise (open or refresh it), clear (count one clean
 * evaluation) or hold (no verdict this time, so the incident and its clear streak stay as they are).
 */
final readonly class IncidentObservation
{
    public const string RAISE = 'raise';

    public const string CLEAR = 'clear';

    public const string HOLD = 'hold';

    /**
     * @param  'raise'|'clear'|'hold'  $outcome
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public string $key,
        public string $outcome,
        public ?IncidentSeverity $severity = null,
        public string $title = '',
        public ?string $detail = null,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function raise(string $key, IncidentSeverity $severity, string $title, ?string $detail, array $context = []): self
    {
        return new self($key, self::RAISE, $severity, $title, $detail, $context);
    }

    public static function clear(string $key): self
    {
        return new self($key, self::CLEAR);
    }

    public static function hold(string $key): self
    {
        return new self($key, self::HOLD);
    }
}
