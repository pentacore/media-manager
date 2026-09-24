<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed global push destinations. Each row is a Laravel
     * notifiable that AdminNotifier adds to every admin fan-out; config
     * holds the channel's destination fields (topic / url / chat_id /
     * secret) and is encrypted on the model.
     */
    public function up(): void
    {
        Schema::create('notification_destinations', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('channel'); // ntfy | discord | telegram | webhook
            $blueprint->string('label', 60);
            $blueprint->text('config');
            $blueprint->boolean('is_enabled')->default(true);
            $blueprint->string('min_severity')->default('info');
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_destinations');
    }
};
