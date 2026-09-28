<?php

declare(strict_types=1);

namespace App\Http\Streaming;

/**
 * Whether the browser behind the current chat stream is still connected.
 *
 * ChatStreamProtocol keeps the request alive after a disconnect
 * (ignore_user_abort) so usage is still recorded, and calls watch() to mark
 * the request as a stream whose agent should stop at its next step once PHP
 * has noticed the client left (a frame write failed). Unwatched — every
 * queued job, CLI run and non-streamed request — it never reports a
 * disconnect. Scoped: the flag belongs to one request.
 */
class ClientConnection
{
    private bool $watching = false;

    public function watch(): void
    {
        $this->watching = true;
    }

    public function disconnected(): bool
    {
        return $this->watching && $this->connectionAborted();
    }

    protected function connectionAborted(): bool
    {
        return connection_aborted() === 1;
    }
}
