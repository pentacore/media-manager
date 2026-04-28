<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposed_workflows', function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->uuid('conversation_id')->nullable()->index();
            $blueprint->string('rationale', 1000);
            $blueprint->jsonb('steps');
            $blueprint->string('status', 32);
            $blueprint->timestamps();

            $blueprint->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposed_workflows');
    }
};
