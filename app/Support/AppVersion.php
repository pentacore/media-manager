<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class AppVersion
{
    public const string CACHE_KEY = 'app:latest_version';

    public const string REPO = 'pentacore/media-manager';

    /**
     * The running version, baked into the image at build time
     * (APP_VERSION build-arg). "dev" outside released images.
     */
    public static function current(): string
    {
        $version = config('app.version');

        return is_string($version) && $version !== '' ? $version : 'dev';
    }

    /**
     * Latest published GitHub release, cached by app:check-version.
     */
    public static function latest(): ?string
    {
        $latest = Cache::get(self::CACHE_KEY);

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    public static function updateAvailable(): bool
    {
        $current = self::current();
        $latest = self::latest();

        return $current !== 'dev'
            && $latest !== null
            && version_compare($latest, $current, '>');
    }
}
