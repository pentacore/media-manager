<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * SABnzbd answered a read with HTTP 200 but no usable answer: a refusal
 * (`status: false` or an `error`, such as a wrong API key) or a body that is
 * not the JSON object the mode returns (a reverse-proxy login page, a bare
 * scalar, a missing or non-object section). SabnzbdClient throws this instead
 * of reading the queue or history as empty.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch already treats it as an
 * upstream failure; a 200 is neither a client nor a server error. The message
 * is the fixed sentence given here: the parent would quote the body.
 */
final class SabnzbdRefused extends RequestException
{
    public function __construct(Response $response, string $message)
    {
        parent::__construct($response);

        $this->message = $message;
    }
}
