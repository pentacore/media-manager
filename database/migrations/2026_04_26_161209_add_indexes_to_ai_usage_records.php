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
            $blueprint->index('created_at');
            $blueprint->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['created_at']);
            $blueprint->dropIndex(['provider', 'model']);
        });
    }
};
