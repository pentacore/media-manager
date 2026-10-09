<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_outcomes', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('gate');
            $blueprint->string('subject_key', 100);
            $blueprint->string('question', 100);
            $blueprint->string('predicted', 100)->nullable();
            $blueprint->decimal('probability', 5, 4)->nullable();
            $blueprint->decimal('threshold', 5, 4)->nullable();
            $blueprint->string('verdict');
            $blueprint->boolean('outcome_positive')->nullable();
            $blueprint->string('outcome_detail')->nullable();
            $blueprint->timestamp('outcome_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['gate', 'created_at']);
            $blueprint->index(['gate', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_outcomes');
    }
};
