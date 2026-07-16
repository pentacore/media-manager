<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_cases', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('bazarr_connection_id')->constrained('service_connections')->restrictOnDelete();
            $blueprint->foreignId('service_connection_id')->constrained()->restrictOnDelete();
            $blueprint->foreignId('download_action_request_id')->nullable()->constrained('action_requests')->nullOnDelete();
            $blueprint->foreignId('replacement_action_request_id')->nullable()->constrained('action_requests')->nullOnDelete();
            $blueprint->string('media_type');
            $blueprint->string('scope');
            $blueprint->json('target_ids');
            $blueprint->string('file_fingerprint', 64);
            $blueprint->json('required_languages');
            $blueprint->string('requirements_fingerprint', 64);
            $blueprint->string('status')->default('observing');
            $blueprint->json('evidence')->nullable();
            $blueprint->string('failure_reason', 1000)->nullable();
            $blueprint->timestamp('grace_until')->nullable();
            $blueprint->timestamp('observed_at');
            $blueprint->timestamp('resolved_at')->nullable();
            $blueprint->timestamp('superseded_at')->nullable();
            $blueprint->timestamps();

            $blueprint->unique([
                'bazarr_connection_id',
                'service_connection_id',
                'file_fingerprint',
                'requirements_fingerprint',
            ], 'subtitle_cases_material_identity_unique');
            $blueprint->index(['status', 'grace_until']);
            $blueprint->index('download_action_request_id');
            $blueprint->index('replacement_action_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_cases');
    }
};
