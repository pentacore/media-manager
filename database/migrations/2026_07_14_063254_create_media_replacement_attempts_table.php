<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_replacement_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->index();
            $table->string('scope');
            $table->json('target');
            $table->string('candidate_fingerprint', 64)->index();
            $table->json('candidate');
            $table->json('required_languages');
            $table->string('download_id')->nullable()->index();
            $table->json('verification')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_replacement_attempts');
    }
};
