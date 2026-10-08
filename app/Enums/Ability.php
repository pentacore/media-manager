<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * The capability vocabulary. Each value is a Gate name (`can:<value>` route
 * middleware) and a key of the `auth.can` Inertia prop, so the values stay
 * kebab-case. Typefinder emits it as the frontend `Ability` union;
 * App\Support\Abilities maps each case to its minimum role.
 */
enum Ability: string
{
    use EnumUtils;

    case ViewLibrary = 'view-library';
    case RequestMedia = 'request-media';
    case ManageLibrary = 'manage-library';
    case ManageRequests = 'manage-requests';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::ViewLibrary => 'View library',
            self::RequestMedia => 'Request media',
            self::ManageLibrary => 'Manage library',
            self::ManageRequests => 'Manage requests',
            self::Admin => 'Admin',
        };
    }
}
