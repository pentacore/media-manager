<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

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
        AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public function forget(string $key): void
    {
        AppSetting::where('key', $key)->delete();
        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
