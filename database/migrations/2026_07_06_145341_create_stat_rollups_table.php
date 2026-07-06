<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stat_rollups', function (Blueprint $table): void {
            $table->id();
            $table->string('metric');
            $table->string('period', 8); // 'hour' | 'day'
            $table->timestampTz('bucket');
            $table->jsonb('dimensions')->default('{}');
            $table->bigInteger('count')->default(0);
            $table->decimal('sum', 20, 4)->nullable();
            $table->decimal('min', 20, 4)->nullable();
            $table->decimal('max', 20, 4)->nullable();
            $table->timestamps();

            $table->unique(['metric', 'period', 'bucket', 'dimensions']);
            $table->index(['metric', 'period', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stat_rollups');
    }
};
