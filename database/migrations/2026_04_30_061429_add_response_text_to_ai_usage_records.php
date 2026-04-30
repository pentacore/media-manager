<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            // Captured at write-time so the AI Usage detail modal can show
            // the assistant's final text without a re-run. longText (~4 GB
            // ceiling) is overkill for chat replies, but tool-heavy runs
            // can produce long summaries; the listener still truncates to
            // a safe cap so a runaway response can't bloat a row.
            $blueprint->longText('response_text')->nullable()->after('reasoning_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('response_text');
        });
    }
};
