<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emby_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emby_user_link_id')->constrained()->cascadeOnDelete();
            $table->string('media_type');
            $table->string('media_title');
            $table->string('series_title')->nullable();
            $table->string('emby_item_id');
            $table->string('action');
            $table->bigInteger('duration_ticks')->nullable();
            $table->bigInteger('play_position')->nullable();
            $table->timestamps();
            $table->index(['emby_user_link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emby_activities');
    }
};
