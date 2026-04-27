<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', []);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ]);

        $conversationId = $validated['conversation_id'] ?? null;
        $user = $request->user();

        if ($conversationId !== null && ! $this->conversationBelongsToUser($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        try {
            $agent = $conversationId
                ? (new MediaAgent)->continue($conversationId, as: $user)
                : (new MediaAgent)->forUser($user);
            $response = $agent->prompt($validated['message']);
        } catch (Throwable $throwable) {
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

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId ?? null,
        ]);
    }

    private function conversationBelongsToUser(string $conversationId, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
