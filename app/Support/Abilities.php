<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The capability vocabulary shared by route middleware (`can:<ability>`),
 * the `auth.can` Inertia prop and the frontend `useCan()` composable. Each
 * ability is granted from a minimum UserRole, so the role hierarchy stays
 * the single source of truth. This is the only place that defines Gates.
 */
final class Abilities
{
    public const string VIEW_LIBRARY = 'view-library';

    public const string REQUEST_MEDIA = 'request-media';

    public const string MANAGE_LIBRARY = 'manage-library';

    public const string MANAGE_REQUESTS = 'manage-requests';

    public const string ADMIN = 'admin';

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
