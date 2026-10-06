<?php

declare(strict_types=1);

namespace App\Services\Arr;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A Sonarr, Radarr or Prowlarr read answered HTTP 200 with a body that is
 * not a JSON array or object — an SSO or reverse-proxy login page, an HTML
 * error page, a bare scalar. ArrClient throws this instead of returning (and
 * caching) an empty list, so an outage never renders as an empty library.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure; a 200 is neither a client nor a server error, so
 * BaseArrController words it ":service is unreachable right now.". The
 * message is fixed: the parent would quote the body.
 */
final class ArrUnexpectedResponse extends RequestException
{
    public function __construct(Response $response, string $service)
    {
        parent::__construct($response);

        $this->message = sprintf('%s answered with a body that is not JSON data.', $service);

        // RequestException::report() rebuilds $this->message from the raw
        // response body/status unless this is already true — without it, an
        // uncaught instance reaching the exception handler would undo the
        // "never quotes the body" guarantee in this class's docblock.
        $this->hasBeenSummarized = true;
    }
}
