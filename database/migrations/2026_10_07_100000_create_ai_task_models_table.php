<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_models', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('task', 40);
            $blueprint->string('scope', 120)->default('default');
            $blueprint->string('provider', 40)->nullable();
            $blueprint->string('model', 100)->nullable();
            $blueprint->string('reasoning', 20)->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['task', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_models');
    }
};
