<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Ai\AnsweredBy;
use App\Ai\ChatFailure;
use App\Ai\ChatTurnContext;
use Closure;
use Generator;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Override;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * AG-UI for the chat panel, with five app behaviours on top: keep the
 * request alive after a browser disconnect so usage is recorded, while the
 * agent stops at its next step (ClientConnection + StopWhenClientDisconnected),
 * write the frames under Octane, keep them inside a capturing output buffer,
 * and explain failures instead of the SDK's masked "An error occurred."
 * (naming the stored conversation of a failed turn), and name the exact
 * answered-by (model, reasoning, tier) on a completed run's RUN_FINISHED.
 * The SDK's own terminal cases (an interrupt that already finished the run,
 * the unmasked approval-mismatch frame) are left to the parent.
 */
final class ChatStreamProtocol extends AgentUserInteractionProtocol
{
    private ?ChatTurnContext $turnOverride = null;

    /**
     * Remember the turn's model/reasoning override. The frames are written
     * after the controller returns, by which point a host may already have
     * torn down the request's scoped state, so the closing frame re-applies it.
     */
    public function forTurn(ChatTurnContext $chatTurnContext): self
    {
        $this->turnOverride = new ChatTurnContext;
        $this->turnOverride->apply($chatTurnContext->provider, $chatTurnContext->model, $chatTurnContext->reasoning);

        return $this;
    }

    #[Override]
    public function response(StreamableAgentResponse $response): Response
    {
        ignore_user_abort(true);

        // Keep draining after a disconnect so usage is recorded, but let
        // StopWhenClientDisconnected end the run at its next step.
        resolve(ClientConnection::class)->watch();

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
                self::writeFrames($write);

                return;
            }

            ob_start();

            try {
                self::writeFrames($write);
            } finally {
                ob_end_flush();
            }
        });
    }

    /**
     * Run the stream callback, iterating it here when it is a generator.
     * Under Octane, response()->stream() keeps the generator as the callback
     * and leaves iterating it to the server, which FrankenPHP never does, so
     * without this the client receives an empty body.
     */
    private static function writeFrames(Closure $write): void
    {
        $frames = $write();

        if (! $frames instanceof Generator) {
            return;
        }

        foreach ($frames as $frame) {
            echo $frame;

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }
    }

    /**
     * Only a normal completion reaches here; an approval interrupt builds its
     * RUN_FINISHED directly and stays untouched. A resolver failure must not
     * break the end of the stream, so it is reported and the field omitted.
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function completionAttributes(?TextUsage $usage, ?string $reason, ?string $provider, ?string $model): array
    {
        $attributes = parent::completionAttributes($usage, $reason, $provider, $model);

        try {
            if ($this->turnOverride instanceof ChatTurnContext) {
                resolve(ChatTurnContext::class)->apply($this->turnOverride->provider, $this->turnOverride->model, $this->turnOverride->reasoning);
            }

            $attributes['answeredBy'] = AnsweredBy::forChat($provider, $model);
        } catch (Throwable $throwable) {
            report($throwable);
        }

        return $attributes;
    }

    #[Override]
    protected function maskedErrorParts(): Generator
    {
        if ($this->finished || ! $this->exception instanceof Throwable || $this->exception instanceof ApprovalMismatchException) {
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
