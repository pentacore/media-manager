<?php

declare(strict_types=1);

namespace App\Services\Emby;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Emby answered the user list with HTTP 200 but a body that is not a JSON
 * list — an SSO or reverse-proxy login page, an error object. EmbyClient
 * throws this instead of returning an empty user list.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure. The message is fixed: the parent would quote the body.
 */
final class EmbyUnexpectedResponse extends RequestException
{
    public function __construct(Response $response)
    {
        parent::__construct($response);

        $this->message = 'Emby answered with a body that is not a JSON user list.';

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
