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
            $blueprint->string('title', 300)->nullable()->after('origin');
            $blueprint->text('description')->nullable()->after('title');
            $blueprint->json('details')->nullable()->after('description');
            $blueprint->boolean('description_verified')->default(true)->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['title', 'description', 'details', 'description_verified']);
        });
    }
};
