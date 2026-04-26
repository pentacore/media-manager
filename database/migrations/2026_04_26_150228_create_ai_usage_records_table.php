<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('invocation_id')->index();
            $blueprint->string('agent_class')->nullable();
            $blueprint->string('provider')->nullable();
            $blueprint->string('model')->nullable();
            $blueprint->unsignedInteger('prompt_tokens')->default(0);
            $blueprint->unsignedInteger('completion_tokens')->default(0);
            $blueprint->unsignedInteger('cache_read_input_tokens')->default(0);
            $blueprint->unsignedInteger('cache_write_input_tokens')->default(0);
            $blueprint->unsignedInteger('reasoning_tokens')->default(0);
            $blueprint->unsignedInteger('tool_calls_count')->default(0);
            $blueprint->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('conversation_id', 36)->nullable()->index();
            $blueprint->string('status')->default('success');
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
    }
};
