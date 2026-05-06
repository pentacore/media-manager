<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->timestamp('archived_at')->nullable()->after('title');
            $blueprint->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['archived_at']);
            $blueprint->dropColumn('archived_at');
        });
    }
};
