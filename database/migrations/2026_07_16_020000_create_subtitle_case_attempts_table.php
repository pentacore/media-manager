<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_case_attempts', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('subtitle_case_id')->constrained()->restrictOnDelete();
            $blueprint->foreignId('action_request_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('type');
            $blueprint->unsignedSmallInteger('candidate_count')->default(0);
            $blueprint->unsignedSmallInteger('eligible_candidate_count')->default(0);
            $blueprint->json('summary')->nullable();
            $blueprint->string('outcome');
            $blueprint->string('error_category', 100)->nullable();
            $blueprint->timestamp('started_at');
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index('subtitle_case_id');
            $blueprint->index('action_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_case_attempts');
    }
};
