<?php

declare(strict_types=1);

use App\Enums\NotificationSeverity;

test('severities rank info below warning below error', function (): void {
    expect(NotificationSeverity::Info->rank())->toBeLessThan(NotificationSeverity::Warning->rank())
        ->and(NotificationSeverity::Warning->rank())->toBeLessThan(NotificationSeverity::Error->rank());
});

test('atLeast compares a raw severity string against the threshold', function (): void {
    expect(NotificationSeverity::Warning->atLeast('error'))->toBeTrue()
        ->and(NotificationSeverity::Warning->atLeast('warning'))->toBeTrue()
        ->and(NotificationSeverity::Warning->atLeast('info'))->toBeFalse()
        ->and(NotificationSeverity::Warning->atLeast('bogus'))->toBeFalse();
});
