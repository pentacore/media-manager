<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_requests', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('type');
            $blueprint->string('source_service');
            $blueprint->string('target_service');
            $blueprint->string('status')->default('pending');
            $blueprint->boolean('requires_approval')->default(true);
            $blueprint->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->json('payload');
            $blueprint->json('result')->nullable();
            $blueprint->timestamps();
            $blueprint->index('status');
            $blueprint->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_requests');
    }
};
