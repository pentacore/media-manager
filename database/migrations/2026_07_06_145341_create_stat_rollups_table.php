<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stat_rollups', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('metric');
            $blueprint->string('period', 8); // 'hour' | 'day'
            $blueprint->timestampTz('bucket');
            $blueprint->jsonb('dimensions')->default('{}');
            $blueprint->bigInteger('count')->default(0);
            // 26 integer digits: byte-valued gauges (disk free/total) are
            // sampled additively 288×/day, so a 20,4 column would overflow
            // the day bucket for any volume ≥ ~35 TB.
            $blueprint->decimal('sum', 30, 4)->nullable();
            $blueprint->decimal('min', 20, 4)->nullable();
            $blueprint->decimal('max', 20, 4)->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['metric', 'period', 'bucket', 'dimensions']);
            $blueprint->index(['metric', 'period', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stat_rollups');
    }
};
