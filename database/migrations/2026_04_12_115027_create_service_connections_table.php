<?php

use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('url');
            $table->text('api_key');
            $table->text('webhook_token');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('version')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};
