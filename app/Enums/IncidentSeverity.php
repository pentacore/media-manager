<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum IncidentSeverity: string
{
    use EnumUtils;

    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function notificationSeverity(): NotificationSeverity
    {
        return match ($this) {
            self::Warning => NotificationSeverity::Warning,
            self::Critical => NotificationSeverity::Error,
        };
    }
}
