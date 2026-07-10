<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            // How the handler dealt with the event: handled / ignored /
            // no_handler / failed. NULL means not yet processed, or a row
            // written before this feature existed.
            $blueprint->string('handling_status')->nullable()->after('processed_at');
            $blueprint->index('handling_status');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['handling_status']);
            $blueprint->dropColumn('handling_status');
        });
    }
};
