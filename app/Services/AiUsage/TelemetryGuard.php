<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Closure;
use Throwable;

/**
 * Runs a usage or failure listener's write so that a throw is reported and
 * never escapes. These listeners run inside the SDK call: laravel/ai
 * dispatches the failure events from inside its own catch, so a throw there
 * would replace the provider's real error, and a throw on the success path
 * would fail a call that completed. Losing one telemetry row is the lesser
 * harm.
 */
final class TelemetryGuard
{
    public static function run(Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
