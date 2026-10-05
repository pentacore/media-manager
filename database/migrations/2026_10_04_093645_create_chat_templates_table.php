<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_templates', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->string('name', 120);
            $blueprint->text('body');
            $blueprint->json('variables');
            $blueprint->boolean('auto_send')->default(false);
            $blueprint->boolean('pinned')->default(false);
            $blueprint->timestamp('last_used_at')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_templates');
    }
};
