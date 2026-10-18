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
            $blueprint->json('second_opinion')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('second_opinion');
        });
    }
};
