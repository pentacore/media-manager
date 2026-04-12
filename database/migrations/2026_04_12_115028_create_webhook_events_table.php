<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $blueprint->string('event_type');
            $blueprint->json('payload');
            $blueprint->timestamp('processed_at')->nullable();
            $blueprint->timestamps();
            $blueprint->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
