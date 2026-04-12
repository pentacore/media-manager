<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('type');
            $blueprint->string('name');
            $blueprint->string('url');
            $blueprint->text('api_key');
            $blueprint->text('webhook_token');
            $blueprint->boolean('is_active')->default(true);
            $blueprint->timestamp('last_seen_at')->nullable();
            $blueprint->string('version')->nullable();
            $blueprint->json('settings')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};
