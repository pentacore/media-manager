<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emby_user_links', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['user_id', 'emby_user_id']);
            $blueprint->unique('emby_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('emby_user_links', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['emby_user_id']);
            $blueprint->unique(['user_id', 'emby_user_id']);
        });
    }
};
