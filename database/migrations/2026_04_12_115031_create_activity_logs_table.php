<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->foreignId('service_connection_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('action');
            $blueprint->string('subject_type')->nullable();
            $blueprint->unsignedBigInteger('subject_id')->nullable();
            $blueprint->string('description');
            $blueprint->json('metadata')->nullable();
            $blueprint->timestamps();
            $blueprint->index(['subject_type', 'subject_id']);
            $blueprint->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
