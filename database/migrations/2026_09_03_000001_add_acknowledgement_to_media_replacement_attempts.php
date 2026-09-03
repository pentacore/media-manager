<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->timestamp('acknowledged_at')->nullable();
            $blueprint->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // The sidebar badge and the broadcast payload count open needs_attention
        // rows on every request; a partial index keeps that a cheap index scan.
        DB::statement('create index media_replacement_attempts_attention_open_idx on media_replacement_attempts (status) where acknowledged_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists media_replacement_attempts_attention_open_idx');

        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('acknowledged_by');
            $blueprint->dropColumn('acknowledged_at');
        });
    }
};
