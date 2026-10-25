<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->uuid('uuid')->unique();
            $blueprint->string('name', 100)->unique();
            $blueprint->string('hostname', 255)->nullable();
            $blueprint->string('os', 255)->nullable();
            $blueprint->string('kernel', 255)->nullable();
            $blueprint->string('arch', 32)->nullable();
            $blueprint->string('agent_version', 64)->nullable();
            $blueprint->string('token_hash', 64)->nullable();
            $blueprint->string('enrollment_token_hash', 64)->nullable()->unique();
            $blueprint->timestamp('enrollment_expires_at')->nullable();
            $blueprint->timestamp('enrolled_at')->nullable();
            $blueprint->timestamp('last_report_at')->nullable()->index();
            $blueprint->jsonb('last_report')->nullable();
            $blueprint->jsonb('rule_state')->default('{}');
            $blueprint->unsignedInteger('config_version')->default(1);
            $blueprint->unsignedSmallInteger('report_interval_seconds')->default(60);
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
