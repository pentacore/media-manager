<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime_id_maps', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedInteger('anilist_id')->nullable()->index();
            $blueprint->unsignedInteger('mal_id')->nullable()->index();
            $blueprint->unsignedInteger('tmdb_tv_id')->nullable();
            $blueprint->unsignedInteger('tmdb_movie_id')->nullable();
            $blueprint->unsignedInteger('tvdb_id')->nullable();
            $blueprint->unsignedInteger('tmdb_season')->nullable();
            $blueprint->string('type')->nullable();
            // Marks rows created from a user-confirmed fuzzy match rather than
            // the Fribb dataset, so a dataset reload does not clobber them.
            $blueprint->boolean('user_confirmed')->default(false);
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_id_maps');
    }
};
