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
            // Independent, durable record of whether the executor successfully
            // suspended monitoring — distinct from the mutable failure_reason,
            // so the blocklist decision cannot be corrupted by a later status
            // write on a retry.
            $blueprint->boolean('monitoring_suspended')->nullable()->after('was_monitored');
        });
    }

    public function down(): void
    {
        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('monitoring_suspended');
        });
    }
};
