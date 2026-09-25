<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Ai\ChatFailure;
use Generator;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Override;
use Symfony\Component\HttpFoundation\Response;

/**
 * AG-UI for the chat panel, with two app behaviours on top: keep draining
 * after a browser disconnect (usage is only recorded once the stream ends),
 * and explain failures instead of the SDK's masked "An error occurred.".
 * The SDK's own terminal cases (an interrupt that already finished the run,
 * the unmasked approval-mismatch frame) are left to the parent.
 */
final class ChatStreamProtocol extends AgentUserInteractionProtocol
{
    #[Override]
    public function response(StreamableAgentResponse $response): Response
    {
        ignore_user_abort(true);

        return parent::response($response);
    }

    #[Override]
    protected function maskedErrorParts(): Generator
    {
        if ($this->finished || $this->exception === null || $this->exception instanceof ApprovalMismatchException) {
            yield from parent::maskedErrorParts();

            return;
        }

        yield from $this->yieldPart([
            'type' => 'RUN_ERROR',
            'message' => ChatFailure::message($this->exception),
            'code' => ChatFailure::code($this->exception),
        ]);
    }
}
