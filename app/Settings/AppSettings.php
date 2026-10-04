<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AppSettings
{
    private const string CACHE_PREFIX = 'app_settings:';

    private const int CACHE_TTL = 60;

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL,
            fn (): mixed => AppSetting::find($key)?->value ?? $default,
        );
    }

    public function set(string $key, mixed $value): void
    {
        // The `value` column is NOT NULL — a null setter is the canonical
        // "clear it" signal, so route it through forget() instead of
        // attempting an insert that would crash on the constraint.
        if ($value === null) {
            $this->forget($key);

            return;
        }

        AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->forgetCached($key);
    }

    public function forget(string $key): void
    {
        AppSetting::where('key', $key)->delete();
        $this->forgetCached($key);
    }

    /**
     * Inside a transaction a read can cache the uncommitted value (the save
     * reading its own write) or, on another worker, the old committed one.
     * Forget the key now, again once the write commits, and again if it rolls
     * back, so neither outlives the transaction. Outside a transaction the
     * after-commit forget runs at once and the rollback one never does.
     */
    private function forgetCached(string $key): void
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        Cache::forget($cacheKey);
        DB::afterCommit(static fn (): bool => Cache::forget($cacheKey));
        DB::afterRollBack(static fn (): bool => Cache::forget($cacheKey));
    }
}
