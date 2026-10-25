<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $blueprint): void {
            $blueprint->string('last_report_error', 500)->nullable();
            $blueprint->timestamp('last_report_error_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['last_report_error', 'last_report_error_at']);
        });
    }
};
