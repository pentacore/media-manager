<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_price_refresh_runs', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('mode');
            $blueprint->string('trigger');
            $blueprint->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->string('status');
            $blueprint->string('models_dev_status')->nullable();
            $blueprint->unsignedInteger('providers_requested')->default(0);
            $blueprint->unsignedInteger('providers_succeeded')->default(0);
            $blueprint->unsignedInteger('providers_failed')->default(0);
            $blueprint->unsignedInteger('models_created')->default(0);
            $blueprint->unsignedInteger('models_updated')->default(0);
            $blueprint->unsignedInteger('models_unchanged')->default(0);
            $blueprint->unsignedInteger('models_locked')->default(0);
            $blueprint->unsignedInteger('models_rejected')->default(0);
            $blueprint->unsignedInteger('models_tiered')->default(0);
            $blueprint->json('fallback_targets')->nullable();
            $blueprint->json('unverified_targets')->nullable();
            $blueprint->json('provider_results')->nullable();
            $blueprint->text('error_message')->nullable();
            $blueprint->timestamp('started_at');
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_price_refresh_runs');
    }
};
