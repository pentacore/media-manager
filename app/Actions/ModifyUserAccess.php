<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Role changes and account deletion that can never leave the app without an
 * admin. The admin count and the write run under one advisory lock, so two
 * admins demoting or deleting each other concurrently cannot both succeed.
 */
final class ModifyUserAccess
{
    public const string LOCK_KEY = 'users:admin-roster';

    /**
     * @param  (Closure(): void)|null  $beforeDelete  runs inside the lock after the check, before the row is deleted (e.g. logging the user out)
     *
     * @throws LastAdminException
     */
    public function delete(User $user, ?Closure $beforeDelete = null): void
    {
        $this->whileAnAdminRemains($user, removesAdmin: true, mutation: function () use ($user, $beforeDelete): void {
            if ($beforeDelete instanceof Closure) {
                $beforeDelete();
            }

            $user->delete();
        });
    }

    /**
     * @throws LastAdminException
     */
    public function changeRole(User $user, UserRole $userRole): void
    {
        $this->whileAnAdminRemains($user, removesAdmin: $userRole !== UserRole::Admin, mutation: function () use ($user, $userRole): void {
            $user->update(['role' => $userRole]);
        });
    }

    /**
     * @param  Closure(): void  $mutation
     *
     * @throws LastAdminException
     */
    private function whileAnAdminRemains(User $user, bool $removesAdmin, Closure $mutation): void
    {
        DB::transaction(function () use ($user, $removesAdmin, $mutation): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);

            $isAdminNow = User::query()->whereKey($user->id)->where('role', UserRole::Admin->value)->exists();

            if ($removesAdmin && $isAdminNow) {
                $otherAdmins = User::query()
                    ->where('role', UserRole::Admin->value)
                    ->whereKeyNot($user->id)
                    ->count();

                throw_if($otherAdmins === 0, LastAdminException::class);
            }

            $mutation();
        });
    }
}
