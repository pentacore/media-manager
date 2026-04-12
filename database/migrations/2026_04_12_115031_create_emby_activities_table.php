<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('emby_user_link_id')->constrained()->cascadeOnDelete();
            $blueprint->string('media_type');
            $blueprint->string('media_title');
            $blueprint->string('series_title')->nullable();
            $blueprint->string('emby_item_id');
            $blueprint->string('action');
            $blueprint->bigInteger('duration_ticks')->nullable();
            $blueprint->bigInteger('play_position')->nullable();
            $blueprint->timestamps();
            $blueprint->index(['emby_user_link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emby_activities');
    }
};
