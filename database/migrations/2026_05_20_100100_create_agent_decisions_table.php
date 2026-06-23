<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_decisions', function (Blueprint $blueprint): void {
            $blueprint->id();
            // Nullable + nullOnDelete: when webhook capture is disabled the
            // WebhookEvent row is trimmed after processing, but the decision
            // (and its audit trail) should survive.
            $blueprint->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('service')->nullable();
            $blueprint->string('event_type')->nullable();
            $blueprint->string('status');
            $blueprint->text('summary')->nullable();
            $blueprint->unsignedInteger('actions_count')->default(0);
            $blueprint->json('action_request_ids')->nullable();
            $blueprint->timestamps();

            // Dedupe key: one decision per processed webhook event. (NULL
            // webhook_event_id rows — capture disabled — are exempt, which
            // matches every supported DB's "multiple NULLs allowed" rule.)
            $blueprint->unique('webhook_event_id');
            $blueprint->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_decisions');
    }
};
