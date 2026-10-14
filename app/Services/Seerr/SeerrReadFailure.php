<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Enums\ServiceType;
use App\Models\User;
use App\Support\UpstreamRefusal;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * How a failed Seerr read is worded. A refusal — a 4xx, or a 200 that is not
 * JSON data (SeerrUnexpectedResponse, usually a login page in front of
 * Seerr) — needs someone to fix the connection, and a retry gets the same
 * answer, so it gets the refusal copy for who can fix it. Anything else (a
 * 5xx, a connection failure) is an outage.
 */
final class SeerrReadFailure
{
    public static function isRefusal(Throwable $throwable): bool
    {
        return $throwable instanceof SeerrUnexpectedResponse
            || ($throwable instanceof RequestException && $throwable->response->clientError());
    }

    public static function message(Throwable $throwable, ?User $user): string
    {
        return self::isRefusal($throwable)
            ? UpstreamRefusal::message(ServiceType::Seerr, $user)
            : __(':service is unreachable right now.', ['service' => ServiceType::Seerr->label()]);
    }
}
