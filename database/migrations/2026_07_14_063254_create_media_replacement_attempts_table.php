<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('action_request_id')->unique()->constrained()->cascadeOnDelete();
            $blueprint->foreignId('service_connection_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('status')->index();
            $blueprint->string('scope');
            $blueprint->json('target');
            $blueprint->string('candidate_fingerprint', 64)->index();
            $blueprint->json('candidate');
            $blueprint->json('required_languages');
            $blueprint->string('download_id')->nullable()->index();
            $blueprint->json('verification')->nullable();
            $blueprint->text('failure_reason')->nullable();
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_replacement_attempts');
    }
};
