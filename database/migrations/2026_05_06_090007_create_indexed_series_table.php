<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexed_series', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedInteger('sonarr_id');
            $blueprint->unsignedInteger('tvdb_id')->nullable();
            $blueprint->unsignedInteger('imdb_id')->nullable();
            $blueprint->string('title');
            $blueprint->string('sort_title')->nullable();
            $blueprint->unsignedSmallInteger('year')->nullable();
            $blueprint->string('title_slug')->nullable();
            $blueprint->string('status', 32)->nullable();
            $blueprint->boolean('monitored')->default(false);
            $blueprint->string('network', 128)->nullable();
            $blueprint->json('genres')->nullable();
            $blueprint->text('overview')->nullable();
            $blueprint->string('poster_url', 1024)->nullable();
            $blueprint->timestamp('arr_added_at')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['service_connection_id', 'sonarr_id']);
            $blueprint->index('tvdb_id');
            $blueprint->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexed_series');
    }
};
