<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bazarr_service_links', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('bazarr_connection_id')->constrained('service_connections')->cascadeOnDelete();
            $blueprint->foreignId('related_connection_id')->constrained('service_connections')->cascadeOnDelete();
            $blueprint->string('role');
            $blueprint->timestamps();
            $blueprint->unique(['bazarr_connection_id', 'role']);
            $blueprint->unique(['bazarr_connection_id', 'related_connection_id']);
            $blueprint->index('related_connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bazarr_service_links');
    }
};
