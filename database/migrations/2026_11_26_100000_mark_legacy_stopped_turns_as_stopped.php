<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * From 2026-09-28 to 2026-11-03 a Stop was saved as a failure: the chat turn
 * as a `failed` assistant message with `meta.error` set to the client
 * disconnect message, and its usage row as `failed` with that message. Since
 * then a Stop is a `completed` turn with `meta.stopped` and a `stopped` usage
 * row with no error (StoppedByUser). This rewrites those old rows to the
 * current shape so they stop reading as failures in the chat, AI Usage and
 * routing outcomes. Only the exact message matches (the text of
 * ClientDisconnectedException::MESSAGE, copied here so a later change to it
 * can't change what this migration did). Sub-agent tool rows from that time
 * were stored as `success` and can't be told apart, so they stay.
 */
return new class extends Migration
{
    private const string STOP_MESSAGE = 'The chat client disconnected; the turn was stopped before its next step.';

    public function up(): void
    {
        DB::table('agent_conversation_messages')
            ->where('role', 'assistant')
            ->where('status', 'failed')
            ->where('meta', 'like', '%the turn was stopped before its next step%')
            ->select(['id', 'meta'])
            ->chunkById(500, function (iterable $rows): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);

                    if (! is_array($meta) || ($meta['error'] ?? null) !== self::STOP_MESSAGE) {
                        continue;
                    }

                    unset($meta['error']);
                    $meta['stopped'] = true;

                    DB::table('agent_conversation_messages')->where('id', $row->id)->update([
                        'status' => 'completed',
                        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                    ]);
                }
            });

        DB::table('ai_usage_records')
            ->where('status', 'failed')
            ->where('error_message', self::STOP_MESSAGE)
            ->update(['status' => 'stopped', 'error_message' => null, 'error_code' => null]);
    }

    /**
     * Deliberately a no-op: putting the stops back as failures would only
     * restore the mislabelling, and the rewritten rows can't be told apart
     * from stops recorded after the change.
     */
    public function down(): void {}
};
