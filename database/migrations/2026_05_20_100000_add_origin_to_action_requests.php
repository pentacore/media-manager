<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_requests', function (Blueprint $blueprint): void {
            // Distinguishes who created the request: 'system' (deterministic
            // webhook handlers), 'chat' (the interactive MediaAgent), or
            // 'agent' (the autonomous DecisionAgent). Existing rows are
            // deterministic handler output, so 'system' is the right backfill.
            $blueprint->string('origin')->default('system')->after('type');
            $blueprint->index('origin');
        });
    }

    public function down(): void
    {
        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['origin']);
            $blueprint->dropColumn('origin');
        });
    }
};
