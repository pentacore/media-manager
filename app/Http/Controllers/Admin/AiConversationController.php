<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiConversationController extends Controller
{
    private const int PER_PAGE = 25;

    private const array STATES = ['active', 'archived', 'all'];

    public function index(Request $request): Response
    {
        $state = $this->resolveState($request);
        $userId = $request->integer('user_id') ?: null;
        $search = trim((string) $request->string('q'));

        $builder = DB::table('agent_conversations')
            ->select([
                'agent_conversations.id',
                'agent_conversations.participant_type',
                'agent_conversations.participant_id',
                'agent_conversations.title',
                'agent_conversations.archived_at',
                'agent_conversations.updated_at',
                'agent_conversations.created_at',
                DB::raw('(SELECT COUNT(*) FROM agent_conversation_messages WHERE agent_conversation_messages.conversation_id = agent_conversations.id) AS message_count'),
            ])
            ->latest('agent_conversations.updated_at');

        $this->applyStateFilter($builder, $state);

        if ($userId !== null) {
            $builder
                ->where('agent_conversations.participant_type', (new User)->getMorphClass())
                ->where('agent_conversations.participant_id', $userId);
        }

        if ($search !== '') {
            $builder->where('agent_conversations.title', 'like', '%'.$search.'%');
        }

        $lengthAwarePaginator = $builder->paginate(
            perPage: self::PER_PAGE,
            page: $request->integer('page', 1) ?: 1,
        )->withQueryString();

        $userIds = collect($lengthAwarePaginator->items())
            ->filter(fn ($row): bool => $row->participant_type === (new User)->getMorphClass())
            ->pluck('participant_id')
            ->filter()
            ->unique()
            ->all();

        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $rows = collect($lengthAwarePaginator->items())->map(fn ($row): array => [
            'id' => $row->id,
            'title' => (string) $row->title,
            'archived_at' => $row->archived_at,
            'updated_at' => $row->updated_at,
            'created_at' => $row->created_at,
            'message_count' => (int) $row->message_count,
            'user' => $row->participant_id !== null && $usersById->has($row->participant_id)
                ? [
                    'id' => $usersById[$row->participant_id]->id,
                    'name' => $usersById[$row->participant_id]->name,
                    'email' => $usersById[$row->participant_id]->email,
                ]
                : null,
        ])->all();

        return Inertia::render('Admin/AiConversations/Index', [
            'conversations' => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                    'total' => $lengthAwarePaginator->total(),
                ],
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'state' => $state,
                'user_id' => $userId,
                'q' => $search,
            ],
            'states' => self::STATES,
        ]);
    }

    public function show(string $conversation): Response
    {
        $row = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->first();

        abort_if($row === null, 404);

        $owner = $row->participant_type === (new User)->getMorphClass() && $row->participant_id !== null
            ? User::query()->find($row->participant_id, ['id', 'name', 'email'])
            : null;

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get(['role', 'content', 'tool_calls', 'tool_results', 'created_at'])
            ->map(fn ($message): array => [
                'role' => (string) $message->role,
                'text' => (string) $message->content,
                'tool_calls' => json_decode((string) $message->tool_calls, true) ?: [],
                'tool_results' => json_decode((string) $message->tool_results, true) ?: [],
                'created_at' => $message->created_at,
            ])
            ->all();

        return Inertia::render('Admin/AiConversations/Show', [
            'conversation' => [
                'id' => $row->id,
                'title' => (string) $row->title,
                'archived_at' => $row->archived_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'user' => $owner instanceof User ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ] : null,
            ],
            'messages' => $messages,
        ]);
    }

    public function archive(string $conversation): RedirectResponse
    {
        $updated = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->whereNull('archived_at')
            ->update(['archived_at' => now(), 'updated_at' => now()]);

        abort_if($updated === 0 && ! DB::table('agent_conversations')->where('id', $conversation)->exists(), 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation archived.')]);

        return back();
    }

    public function unarchive(string $conversation): RedirectResponse
    {
        abort_unless(
            DB::table('agent_conversations')->where('id', $conversation)->exists(),
            404,
        );

        DB::table('agent_conversations')
            ->where('id', $conversation)
            ->update(['archived_at' => null, 'updated_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation unarchived.')]);

        return back();
    }

    public function destroy(string $conversation): RedirectResponse
    {
        abort_unless(
            DB::table('agent_conversations')->where('id', $conversation)->exists(),
            404,
        );

        DB::transaction(function () use ($conversation): void {
            DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversation)
                ->delete();

            DB::table('agent_conversations')
                ->where('id', $conversation)
                ->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation deleted.')]);

        return to_route('admin.ai-conversations.index');
    }

    private function resolveState(Request $request): string
    {
        $state = (string) $request->string('state', 'active');

        return in_array($state, self::STATES, true) ? $state : 'active';
    }

    private function applyStateFilter(Builder $builder, string $state): void
    {
        match ($state) {
            'archived' => $builder->whereNotNull('agent_conversations.archived_at'),
            'all' => null,
            default => $builder->whereNull('agent_conversations.archived_at'),
        };
    }
}
