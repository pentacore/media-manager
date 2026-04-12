<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emby_user_links', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->string('emby_user_id');
            $blueprint->string('emby_username');
            $blueprint->timestamps();
            $blueprint->unique(['user_id', 'emby_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emby_user_links');
    }
};
