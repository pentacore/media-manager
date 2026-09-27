<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class RegistrationGate
{
    /**
     * Self-registration is open for the bootstrap admin (no users yet) and
     * otherwise only when explicitly enabled.
     */
    public static function isOpen(): bool
    {
        return (bool) config('mediamanager.registration_enabled') || ! User::query()->exists();
    }
}
