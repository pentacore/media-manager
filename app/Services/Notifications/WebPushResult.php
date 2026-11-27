<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Outcome of one Web Push send. `expired` means the push service answered
 * 404 or 410: the browser subscription is gone for good.
 */
final readonly class WebPushResult
{
    public function __construct(
        public bool $success,
        public bool $expired,
        public ?string $reason,
    ) {}
}
