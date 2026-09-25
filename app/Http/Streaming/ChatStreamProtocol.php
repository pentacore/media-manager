<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Ai\ChatFailure;
use Closure;
use Generator;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Override;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AG-UI for the chat panel, with three app behaviours on top: keep draining
 * after a browser disconnect (usage is only recorded once the stream ends),
 * keep frames inside a capturing output buffer, and explain failures instead
 * of the SDK's masked "An error occurred." (naming the stored conversation
 * of a failed turn).
 * The SDK's own terminal cases (an interrupt that already finished the run,
 * the unmasked approval-mismatch frame) are left to the parent.
 */
final class ChatStreamProtocol extends AgentUserInteractionProtocol
{
    #[Override]
    public function response(StreamableAgentResponse $response): Response
    {
        ignore_user_abort(true);

        $streamedResponse = parent::response($response);

        if (! $streamedResponse instanceof StreamedResponse || ! ($write = $streamedResponse->getCallback()) instanceof Closure) {
            return $streamedResponse;
        }

        return $streamedResponse->setCallback(static function () use ($write): void {
            // Laravel's generator stream ob_flush()es after every frame. At
            // SAPI depth (level <= 1) that is what pushes SSE frames to the
            // client. Deeper nesting means a capturing harness (the browser
            // test server) owns the buffer stack, and flushing there pushes
            // the frames past its capture — so give the flushes a buffer of
            // our own that drains into the harness's buffer instead.
            if (ob_get_level() <= 1) {
                $write();

                return;
            }

            ob_start();

            try {
                $write();
            } finally {
                ob_end_flush();
            }
        });
    }

    #[Override]
    protected function maskedErrorParts(): Generator
    {
        if ($this->finished || $this->exception === null || $this->exception instanceof ApprovalMismatchException) {
            yield from parent::maskedErrorParts();

            return;
        }

        $conversationId = $this->storedConversationId();

        yield from $this->yieldPart([
            'type' => 'RUN_ERROR',
            'message' => ChatFailure::message($this->exception),
            'code' => ChatFailure::code($this->exception),
            // 1.0 stores a failed turn once a step completed; naming that
            // conversation lets the client adopt it instead of starting over.
            ...($conversationId === null ? [] : ['threadId' => $conversationId]),
        ]);
    }

    /**
     * The run's conversation id, only once a conversation row exists — a
     * turn that died before its first step leaves just a pending id.
     */
    private function storedConversationId(): ?string
    {
        $conversationId = $this->response?->conversationId;

        if ($conversationId === null || ! DB::table('agent_conversations')->where('id', $conversationId)->exists()) {
            return null;
        }

        return $conversationId;
    }
}
