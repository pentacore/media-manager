<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_decisions', function (Blueprint $blueprint): void {
            $blueprint->string('invocation_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_decisions', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['invocation_id']);
            $blueprint->dropColumn('invocation_id');
        });
    }
};
