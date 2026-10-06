<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime_id_maps', function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('tvdb_season')->nullable()->after('tmdb_season');
        });
    }

    public function down(): void
    {
        Schema::table('anime_id_maps', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('tvdb_season');
        });
    }
};
