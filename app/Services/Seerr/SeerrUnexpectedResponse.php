<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A Seerr read answered HTTP 200 with a body that is not a JSON object or
 * list — an SSO or reverse-proxy login page, an HTML error page, a bare
 * scalar. SeerrClient throws this instead of returning (and caching) an
 * empty result, so a login page never reads as "no requests".
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure. The message is fixed: the parent would quote the body.
 */
final class SeerrUnexpectedResponse extends RequestException
{
    public function __construct(Response $response)
    {
        parent::__construct($response);

        $this->message = 'Seerr answered with a body that is not JSON data.';

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
