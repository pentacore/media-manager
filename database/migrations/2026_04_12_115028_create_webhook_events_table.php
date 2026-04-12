<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
