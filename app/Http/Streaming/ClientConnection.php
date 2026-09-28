<?php

declare(strict_types=1);

namespace App\Http\Streaming;

/**
 * Whether the browser behind the current chat stream is still connected.
 *
 * ChatStreamProtocol keeps the request alive after a disconnect
 * (ignore_user_abort) so usage is still recorded, and calls watch() to mark
 * the request as a stream whose agent should stop at its next step once PHP
 * has noticed the client left. PHP notices on a flush that observes the
 * client gone — under FrankenPHP, any flush after the request context was
 * cancelled, even one that writes nothing — so disconnected() polls with a
 * flush of its own first, catching a disconnect that happened after the last
 * frame. Unwatched — every queued job, CLI run and non-streamed request — it
 * neither polls nor reports a disconnect. Scoped: the flag belongs to one
 * request.
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
        if (! $this->watching) {
            return false;
        }

        $this->pollConnection();

        return $this->connectionAborted();
    }

    /**
     * Let the SAPI check the client: a flush is where PHP learns of an abort.
     */
    protected function pollConnection(): void
    {
        flush();
    }

    protected function connectionAborted(): bool
    {
        return connection_aborted() === 1;
    }
}
