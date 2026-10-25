<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_checks', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->uuid('uuid')->unique();
            $blueprint->foreignId('server_id')->constrained()->cascadeOnDelete();
            $blueprint->string('type', 40);
            $blueprint->jsonb('params')->default('{}');
            $blueprint->boolean('is_enabled')->default(true);
            $blueprint->string('last_status', 16)->nullable();
            $blueprint->string('last_message', 500)->nullable();
            $blueprint->jsonb('last_observed')->nullable();
            $blueprint->timestamp('last_evaluated_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['server_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_checks');
    }
};
