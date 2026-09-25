<?php

declare(strict_types=1);

namespace App\Ai;

use App\Services\AiBudget\AiBudgetExceededException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * User-facing wording for a failed chat turn — shared by the JSON pre-flight
 * path and the AG-UI RUN_ERROR frame so both explain failures the same way.
 */
final class ChatFailure
{
    public static function code(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof RateLimitedException => 'rate_limited',
            $throwable instanceof AiBudgetExceededException => 'budget_exceeded',
            $throwable instanceof ProviderConnectionException => 'provider_unreachable',
            default => 'ai_error',
        };
    }

    public static function message(Throwable $throwable): string
    {
        return match (self::code($throwable)) {
            'rate_limited', 'budget_exceeded' => $throwable->getMessage(),
            'provider_unreachable' => __("Couldn't reach the AI provider. Please try again in a moment."),
            default => __('The AI provider returned an error. Please try again.'),
        };
    }
}
