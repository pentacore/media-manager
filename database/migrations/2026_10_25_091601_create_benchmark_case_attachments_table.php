<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_case_attachments', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('benchmark_case_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedSmallInteger('turn')->nullable();
            $blueprint->string('disk');
            $blueprint->string('path');
            $blueprint->string('original_name');
            $blueprint->string('mime_type');
            $blueprint->unsignedInteger('size');
            $blueprint->timestamps();

            $blueprint->index(['benchmark_case_id', 'turn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_case_attachments');
    }
};
