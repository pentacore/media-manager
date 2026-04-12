<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('source_service');
            $table->string('target_service');
            $table->string('status')->default('pending');
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_requests');
    }
};
