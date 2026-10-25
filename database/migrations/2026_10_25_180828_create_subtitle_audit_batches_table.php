<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_audit_batches', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedInteger('series_id');
            $blueprint->string('series_title');
            $blueprint->unsignedInteger('season_number')->nullable();
            $blueprint->string('status')->default(SubtitleAuditBatchStatus::Searching->value);
            $blueprint->timestamp('search_deadline_at')->nullable();
            $blueprint->timestamp('handed_off_at')->nullable();
            $blueprint->timestamp('card_shown_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['service_connection_id', 'series_id', 'status']);
        });

        Schema::create('subtitle_audit_episodes', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('subtitle_audit_batch_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedInteger('episode_id');
            $blueprint->unsignedInteger('season_number');
            $blueprint->unsignedInteger('episode_number');
            $blueprint->json('missing_languages');
            $blueprint->string('outcome')->default(SubtitleAuditEpisodeOutcome::Searching->value);
            $blueprint->foreignId('action_request_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('release_title')->nullable();
            $blueprint->string('failure_reason')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['subtitle_audit_batch_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_audit_episodes');
        Schema::dropIfExists('subtitle_audit_batches');
    }
};
