<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Capturing a verifier benchmark case from a usage record finds its action request by
 * `second_opinion->>'invocation_id'`, which otherwise scans every action request. The
 * builder cannot express an expression index, hence the raw statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX action_requests_second_opinion_invocation_id_index ON action_requests ((second_opinion->>'invocation_id'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS action_requests_second_opinion_invocation_id_index');
    }
};
