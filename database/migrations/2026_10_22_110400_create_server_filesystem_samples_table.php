<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_filesystem_samples', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('server_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedSmallInteger('resolution');
            $blueprint->timestamp('recorded_at');
            $blueprint->string('mount', 1024);
            $blueprint->string('fstype', 64)->nullable();
            $blueprint->unsignedBigInteger('total_bytes');
            $blueprint->unsignedBigInteger('used_bytes');
            $blueprint->unsignedBigInteger('available_bytes');

            $blueprint->unique(['server_id', 'resolution', 'mount', 'recorded_at']);
            $blueprint->index(['resolution', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_filesystem_samples');
    }
};
