<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\RenameConversationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    private const int RECENT_LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('agent_conversations')
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'title', 'updated_at']);

        return response()->json([
            'data' => $rows->map(fn ($row): array => [
                'id' => $row->id,
                'title' => (string) $row->title,
                'updated_at' => $row->updated_at,
            ])->all(),
        ]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $user = $request->user();

        $row = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->first();

        if ($row === null
            || ($user instanceof User && (int) $row->user_id !== (int) $user->id)
            || $row->archived_at !== null
        ) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get(['role', 'content', 'created_at'])
            ->filter(fn ($message): bool => in_array($message->role, ['user', 'assistant'], true)
                && trim((string) $message->content) !== '')
            ->map(fn ($message): array => [
                'role' => $message->role,
                'text' => (string) $message->content,
                'ts' => Date::parse((string) $message->created_at)->getTimestamp() * 1000,
            ])
            ->values()
            ->all();

        return response()->json([
            'id' => $row->id,
            'title' => (string) $row->title,
            'updated_at' => $row->updated_at,
            'messages' => $messages,
        ]);
    }

    public function rename(RenameConversationRequest $renameConversationRequest, string $conversation): JsonResponse
    {
        $user = $renameConversationRequest->user();

        $row = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->first(['id', 'user_id']);

        if ($row === null || ! $user instanceof User || (int) $row->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $title = $renameConversationRequest->validated()['title'];

        DB::table('agent_conversations')
            ->where('id', $conversation)
            ->update([
                'title' => $title,
                'updated_at' => now(),
            ]);

        return response()->json([
            'id' => $row->id,
            'title' => $title,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
