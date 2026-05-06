<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexed_movies', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedInteger('radarr_id');
            $blueprint->unsignedInteger('tmdb_id')->nullable();
            $blueprint->string('imdb_id', 16)->nullable();
            $blueprint->string('title');
            $blueprint->string('sort_title')->nullable();
            $blueprint->string('original_title')->nullable();
            $blueprint->unsignedSmallInteger('year')->nullable();
            $blueprint->string('title_slug')->nullable();
            $blueprint->string('status', 32)->nullable();
            $blueprint->boolean('monitored')->default(false);
            $blueprint->boolean('has_file')->default(false);
            $blueprint->json('genres')->nullable();
            $blueprint->text('overview')->nullable();
            $blueprint->string('poster_url', 1024)->nullable();
            $blueprint->timestamp('arr_added_at')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['service_connection_id', 'radarr_id']);
            $blueprint->index('tmdb_id');
            $blueprint->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexed_movies');
    }
};
