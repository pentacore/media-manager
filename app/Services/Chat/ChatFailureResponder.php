<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Ai\ChatFailure;
use App\Models\User;
use App\Services\AiBudget\AiBudgetExceededException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * The JSON a chat turn answers when it is refused or its agent fails, shared
 * by ChatController::send() and stream() (before the stream starts) and by
 * ChatTurnGuard's pre-flight. The AG-UI RUN_ERROR frame words the same
 * failures through ChatFailure once a stream is running.
 */
final readonly class ChatFailureResponder
{
    /**
     * Log an agent invocation failure and build the client-facing response.
     * The full message is only surfaced in local for debugging. A rate limit
     * (ours or the provider's, after every failover was exhausted) is the one
     * expected failure and becomes a 429 the chat UI can explain; a hard
     * cap reached mid-run becomes the same 402 as the pre-flight check.
     */
    public function respond(Throwable $throwable, ?User $user): JsonResponse
    {
        if ($throwable instanceof RateLimitedException) {
            return $this->rateLimited($throwable);
        }

        // EnforceBudgetEachStep stops a run that crosses the hard cap mid-way;
        // answer it exactly like the pre-flight budget check does.
        if ($throwable instanceof AiBudgetExceededException) {
            return response()->json([
                'error' => ChatFailure::code($throwable),
                'message' => ChatFailure::message($throwable),
            ], 402);
        }

        if ($throwable instanceof ProviderConnectionException) {
            return response()->json([
                'error' => ChatFailure::code($throwable),
                'message' => ChatFailure::message($throwable),
            ], 503);
        }

        // Laravel's HTTP-client RequestException truncates response bodies
        // in getMessage(); pull the full body separately so OpenAI's
        // verbose error JSON is visible for debugging.
        $context = [
            'user_id' => $user?->id,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ];

        if ($throwable instanceof RequestException) {
            $context['response_body'] = $throwable->response->body();
            $context['response_status'] = $throwable->response->status();
        }

        Log::error('AI request failed.', $context);

        $payload = ['error' => 'AI request failed.'];

        if (app()->isLocal()) {
            $payload['message'] = $throwable->getMessage();
        }

        return response()->json($payload, 500);
    }

    /**
     * A rate limit the chat UI explains: the chat model's own (pre-flight)
     * or one raised during the run.
     */
    public function rateLimited(RateLimitedException $rateLimitedException): JsonResponse
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => $rateLimitedException->getMessage(),
        ], 429);
    }

    /**
     * The monthly hard cap, refused before the agent runs.
     */
    public function budgetExceeded(AiBudgetExceededException $aiBudgetExceededException): JsonResponse
    {
        return response()->json([
            'error' => 'budget_exceeded',
            'message' => $aiBudgetExceededException->getMessage(),
        ], 402);
    }
}
