<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user with the first-user-becomes-admin bootstrap rule applied
 * atomically. The naive `User::count() === 0` check-then-create let two
 * concurrent registrations (Fortify and/or SSO) both observe an empty table
 * and both arrive as Admin; the advisory lock serializes the check and the
 * insert across every code path that creates users.
 */
class CreateUserWithBootstrapRole
{
    /**
     * @param  array<string, mixed>  $attributes  everything except `role`
     */
    public function execute(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ['users:first-admin-bootstrap'],
            );

            return User::create([
                ...$attributes,
                'role' => User::query()->count() === 0 ? UserRole::Admin : UserRole::Viewer,
            ]);
        });
    }
}
