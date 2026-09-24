<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user push destinations for the Discord, Telegram and webhook
     * channels. Global secrets (Telegram bot token) stay in config; these
     * columns decide where a user's pushes go. URLs and secrets are
     * encrypted casts on the User model. Null/empty means the channel
     * skips this user regardless of their preference toggles.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->text('discord_webhook_url')->nullable()->after('ntfy_topic');
            $blueprint->string('telegram_chat_id')->nullable()->after('discord_webhook_url');
            $blueprint->text('webhook_url')->nullable()->after('telegram_chat_id');
            $blueprint->text('webhook_secret')->nullable()->after('webhook_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['discord_webhook_url', 'telegram_chat_id', 'webhook_url', 'webhook_secret']);
        });
    }
};
