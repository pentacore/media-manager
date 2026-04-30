<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user opt-in for each (notification class, severity) pair.
        // The `mail` and `ntfy` columns are reserved — those channels
        // aren't wired up yet, but storing them here keeps the upgrade
        // path painless when they land.
        Schema::create('notification_preferences', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->string('notification_class');
            $blueprint->string('severity'); // info | warning | error
            $blueprint->boolean('database')->default(true);
            $blueprint->boolean('broadcast')->default(true);
            $blueprint->boolean('mail')->default(false);
            $blueprint->boolean('ntfy')->default(false);
            $blueprint->timestamps();

            $blueprint->unique(
                ['user_id', 'notification_class', 'severity'],
                'notification_prefs_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
