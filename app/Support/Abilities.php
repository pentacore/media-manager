<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Ability;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Grants the App\Enums\Ability vocabulary — shared by route middleware
 * (`can:<ability>`), the `auth.can` Inertia prop and the frontend `useCan()`
 * composable — each from a minimum UserRole, so the role hierarchy stays the
 * single source of truth. This is the only place that defines Gates. The
 * constants alias the enum values for `$user->can(Abilities::X)` call sites.
 */
final class Abilities
{
    public const string VIEW_LIBRARY = Ability::ViewLibrary->value;

    public const string REQUEST_MEDIA = Ability::RequestMedia->value;

    public const string MANAGE_LIBRARY = Ability::ManageLibrary->value;

    public const string MANAGE_REQUESTS = Ability::ManageRequests->value;

    public const string ADMIN = Ability::Admin->value;

    /**
     * @var array<string, UserRole>
     */
    public const array MINIMUM_ROLES = [
        self::VIEW_LIBRARY => UserRole::Viewer,
        self::REQUEST_MEDIA => UserRole::Viewer,
        self::MANAGE_LIBRARY => UserRole::Member,
        self::MANAGE_REQUESTS => UserRole::Member,
        self::ADMIN => UserRole::Admin,
    ];

    public static function define(): void
    {
        foreach (self::MINIMUM_ROLES as $ability => $minimumRole) {
            Gate::define($ability, static fn (User $user): bool => $user->role->isAtLeast($minimumRole));
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        $abilities = [];

        foreach (array_keys(self::MINIMUM_ROLES) as $ability) {
            $abilities[$ability] = $user instanceof User && $user->can($ability);
        }

        return $abilities;
    }
}
