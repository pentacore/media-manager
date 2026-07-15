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
            // Set once the arr accepts the grab, so a retry of the same
            // ActionRequest never re-POSTs the release and duplicates the download.
            $table->timestamp('grab_accepted_at')->nullable()->after('download_id');
            // Whether the target was monitored before the executor suspended
            // monitoring, so restoration puts back the original state (and does
            // not start monitoring originally-unmonitored media).
            $table->boolean('was_monitored')->nullable()->after('grab_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $table): void {
            $table->dropColumn(['grab_accepted_at', 'was_monitored']);
        });
    }
};
