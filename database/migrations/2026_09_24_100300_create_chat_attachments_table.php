<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachments', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->string('conversation_id', 36)->nullable()->index();
            $blueprint->string('disk');
            $blueprint->string('path')->unique();
            $blueprint->string('original_name');
            $blueprint->string('mime_type');
            $blueprint->unsignedBigInteger('size');
            $blueprint->json('provider_file_ids')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
    }
};
