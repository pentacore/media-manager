<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_invocations', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('invocation_id')->index();
            $blueprint->string('tool_invocation_id')->nullable();
            $blueprint->string('tool_class');
            $blueprint->string('agent_class')->nullable();
            $blueprint->string('status')->default('success');
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_invocations');
    }
};
