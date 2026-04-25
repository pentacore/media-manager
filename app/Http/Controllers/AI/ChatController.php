<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\CommandAgent;
use App\Ai\Agents\MediaAdvisorAgent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AI/Chat', [
            'agents' => [
                ['value' => 'command', 'label' => 'Command (takes action)'],
                ['value' => 'advisor', 'label' => 'Advisor (read-only)'],
            ],
            'defaultAgent' => config('mediamanager.ai.default_agent', 'command'),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'agent' => ['sometimes', 'string', 'in:command,advisor'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ]);

        $agentKey = $validated['agent'] ?? config('mediamanager.ai.default_agent', 'command');
        $conversationId = $validated['conversation_id'] ?? null;
        $user = $request->user();

        if ($conversationId !== null && ! $this->conversationBelongsToUser($conversationId, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        try {
            $agent = $this->resolveAgent($agentKey, $user, $conversationId);
            $response = $agent->prompt($validated['message']);
        } catch (Throwable $throwable) {
            Log::error('AI request failed.', [
                'user_id' => $user?->id,
                'agent' => $agentKey,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            $payload = ['error' => 'AI request failed.'];

            if (app()->isLocal()) {
                $payload['message'] = $throwable->getMessage();
            }

            return response()->json($payload, 500);
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId ?? null,
            'agent' => $agentKey,
        ]);
    }

    private function resolveAgent(string $key, ?User $user, ?string $conversationId): Agent
    {
        $class = match ($key) {
            'advisor' => MediaAdvisorAgent::class,
            default => CommandAgent::class,
        };

        /** @var Promptable|Agent $agent */
        $agent = new $class;

        return $conversationId
            ? $agent->continue($conversationId, as: $user)
            : $agent->forUser($user);
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
