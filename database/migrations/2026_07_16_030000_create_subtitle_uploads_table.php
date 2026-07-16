<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_uploads', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->foreignId('subtitle_case_id')->constrained()->restrictOnDelete();
            $blueprint->foreignId('action_request_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('path')->unique();
            $blueprint->string('display_name');
            $blueprint->string('checksum', 64);
            $blueprint->string('mime_type');
            $blueprint->string('format', 16);
            $blueprint->unsignedBigInteger('size_bytes');
            $blueprint->timestamp('expires_at')->index();
            $blueprint->timestamp('consumed_at')->nullable();
            $blueprint->timestamp('cancelled_at')->nullable();
            $blueprint->timestamp('cleaned_up_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index('user_id');
            $blueprint->index('subtitle_case_id');
            $blueprint->index('action_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_uploads');
    }
};
