<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $table): void {
            // Marks when the executor has finished its synchronous cleanup
            // (delete + blocklist + monitoring restore) for this run. The tracker
            // must NOT restore monitoring while this is null — otherwise a fast
            // Download webhook could remonitor mid-cleanup and race the blocklist's
            // auto-search. It is the coordination point between the executor's
            // cleanup phase and the tracker's verification phase.
            $table->timestamp('cleanup_completed_at')->nullable()->after('monitoring_suspended');
        });
    }

    public function down(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $table): void {
            $table->dropColumn('cleanup_completed_at');
        });
    }
};
