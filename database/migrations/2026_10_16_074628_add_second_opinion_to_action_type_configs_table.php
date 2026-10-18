<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_type_configs', function (Blueprint $blueprint): void {
            $blueprint->string('second_opinion')->default('off')->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('action_type_configs', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('second_opinion');
        });
    }
};
