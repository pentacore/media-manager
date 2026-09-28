<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

/**
 * A recorded model id without its trailing date-version suffix (e.g.
 * "-2025-09-23"), so dated variants match their catalog row. SQL_REGEX is
 * the Postgres twin every usage query uses; keep the two in sync.
 */
final class BaseModelName
{
    public const string SQL_REGEX = '-[0-9]{4}-[0-9]{2}-[0-9]{2}$';

    public static function of(string $model): string
    {
        return (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);
    }
}
