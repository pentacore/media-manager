<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Migrations\AiMigration;

/**
 * laravel/ai 1.0 stores each assistant turn as `steps` (one entry per model
 * round trip, each tool result on its call) plus a `status`. Backfills the
 * 0.10 `tool_calls` / `tool_results` / `approval_state` shape. Verbatim from
 * the upstream 1.0 upgrade guide, plus a lossy-but-faithful down().
 */
return new class extends AiMigration
{
    public function up(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint): void {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->default(MessageStatus::Completed->value);
        });

        $this->query($table)->where('role', 'user')->update(['steps' => '[]']);

        $this->query($table)
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) use ($table): void {
                foreach ($conversations as $conversation) {
                    $this->backfill($table, $conversation->conversation_id);
                }
            });

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint): void {
            $blueprint->longText('steps')->nullable(false)->change();
            $blueprint->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
            $blueprint->dropIndex('participant_index');
            $blueprint->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropIndex('participant_index');
            $blueprint->index(['participant_type', 'participant_id'], 'participant_index');
            $blueprint->text('tool_calls')->nullable();
            $blueprint->text('tool_results')->nullable();
            $blueprint->text('approval_state')->nullable();
        });

        $this->query($table)->orderBy('id')->chunk(200, function (Collection $rows) use ($table): void {
            foreach ($rows as $row) {
                $calls = collect($this->decoded($row->steps))->flatMap(fn (array $step): array => $step['tool_calls'] ?? []);

                $this->query($table)->where('id', $row->id)->update([
                    'tool_calls' => json_encode($calls->map(fn (array $call): array => collect($call)->only(['id', 'name', 'arguments'])->all())->values()->all()),
                    'tool_results' => json_encode($calls->filter(fn (array $call): bool => array_key_exists('result', $call))->map(fn (array $call): array => collect($call)->only(['id', 'name', 'arguments', 'result'])->all())->values()->all()),
                ]);
            }
        });

        $this->query($table)->whereNull('tool_calls')->update(['tool_calls' => '[]', 'tool_results' => '[]']);

        Schema::connection($this->getConnection())->table($table, function (Blueprint $blueprint): void {
            $blueprint->text('tool_calls')->nullable(false)->change();
            $blueprint->text('tool_results')->nullable(false)->change();
            $blueprint->dropColumn(['steps', 'status']);
        });
    }

    /**
     * Rewrite one conversation's assistant rows as steps, each result landing on the call that made it.
     */
    protected function backfill(string $table, string $conversationId): void
    {
        $rows = $this->query($table)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // A result was recorded on the row of the request that produced it, which may be a later row than its call...
        $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

        foreach ($rows as $row) {
            $meta = $this->decoded($row->meta);

            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn (array $call) => $results->has($call['id'] ?? ''))
                ->map(fn (array $call) => [
                    ...$call,
                    'result' => $results[$call['id']]['result'] ?? null,
                    ...array_filter([
                        'denied' => $results[$call['id']]['denied'] ?? false,
                        'failed' => $results[$call['id']]['failed'] ?? false,
                    ]),
                ])
                ->values()
                ->all();

            $content = (string) $row->content;

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $meta['reasoning'] ?? '')]
                : [$this->step($content, $calls, $meta['reasoning'] ?? '')];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            $this->query($table)->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * Build a conversation step from its content, tool calls, and reasoning.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /**
     * Decode a JSON value into an array, returning an empty array for invalid or empty input.
     *
     * @return array<string, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    /**
     * Create a query builder for the conversation messages table.
     */
    protected function query(string $table): Builder
    {
        return DB::connection($this->getConnection())->table($table);
    }
};
