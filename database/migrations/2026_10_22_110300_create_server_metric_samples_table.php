<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metric_samples', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('server_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedSmallInteger('resolution');
            $blueprint->timestamp('recorded_at');
            $blueprint->float('cpu_percent');
            $blueprint->float('load1');
            $blueprint->unsignedBigInteger('memory_used_bytes');
            $blueprint->unsignedBigInteger('memory_total_bytes');
            $blueprint->unsignedBigInteger('swap_used_bytes');
            $blueprint->unsignedBigInteger('swap_total_bytes');

            $blueprint->unique(['server_id', 'resolution', 'recorded_at']);
            $blueprint->index(['resolution', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_samples');
    }
};
