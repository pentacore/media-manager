<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\User;

/**
 * What a page says when an upstream service answered but refused: a 4xx (a
 * bad API key, a wrong base URL) or a 200 that is not its data (a login page
 * in front of it). The copy depends on who can fix it: admins are sent to
 * the connection settings, everyone else is told to ask an admin.
 */
final class UpstreamRefusal
{
    public static function message(ServiceType $serviceType, ?User $user): string
    {
        return $user?->can(Abilities::ADMIN) === true
            ? __(':service refused the request — check the connection settings.', ['service' => $serviceType->label()])
            : __(':service is unavailable — ask an admin to check the connection.', ['service' => $serviceType->label()]);
    }
}
