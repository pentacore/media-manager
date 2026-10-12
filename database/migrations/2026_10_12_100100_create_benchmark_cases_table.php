<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_cases', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('task', 40);
            $blueprint->string('name', 160);
            $blueprint->jsonb('tags')->default('[]');
            $blueprint->text('notes')->nullable();
            $blueprint->jsonb('input');
            $blueprint->jsonb('fixtures')->default('[]');
            $blueprint->jsonb('scoring');
            $blueprint->string('source_invocation_id')->nullable()->index();
            $blueprint->unsignedInteger('source_prompt_tokens')->nullable();
            $blueprint->unsignedInteger('source_completion_tokens')->nullable();
            $blueprint->boolean('source_truncated')->default(false);
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_cases');
    }
};
