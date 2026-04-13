<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum UserRole: string
{
    use EnumUtils;

    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    public function isAtLeast(self $role): bool
    {
        $hierarchy = [self::Admin->value => 3, self::Member->value => 2, self::Viewer->value => 1];

        return $hierarchy[$this->value] >= $hierarchy[$role->value];
    }
}
