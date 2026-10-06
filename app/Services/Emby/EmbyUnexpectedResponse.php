<?php

declare(strict_types=1);

namespace App\Services\Emby;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Emby answered a read with HTTP 200 but a body that is not the JSON shape
 * expected — an SSO or reverse-proxy login page, an error object. EmbyClient
 * throws this instead of returning an empty user list or treating a login
 * page as system info. The message defaults to the user-list wording and is
 * overridable per call site (e.g. getSystemInfo()'s health check) — always a
 * fixed sentence, never the body.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure.
 */
final class EmbyUnexpectedResponse extends RequestException
{
    public function __construct(Response $response, string $message = 'Emby answered with a body that is not a JSON user list.')
    {
        parent::__construct($response);

        $this->message = $message;

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
