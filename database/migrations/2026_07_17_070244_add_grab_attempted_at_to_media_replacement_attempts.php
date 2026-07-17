<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            // Durable pre-POST marker set immediately before the non-idempotent
            // grab request. grab_accepted_at is only written AFTER the arr
            // responds, so a worker killed between acceptance and that save left
            // no evidence a grab happened — the retry would re-grab and download
            // the release twice. attempted-but-not-accepted now resumes as
            // indeterminate instead.
            $blueprint->timestamp('grab_attempted_at')->nullable()->after('download_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('grab_attempted_at');
        });
    }
};
