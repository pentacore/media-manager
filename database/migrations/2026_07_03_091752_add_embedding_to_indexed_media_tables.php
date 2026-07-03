<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indexed_movies', function (Blueprint $table): void {
            $table->json('embedding')->nullable();
        });

        Schema::table('indexed_series', function (Blueprint $table): void {
            $table->json('embedding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('indexed_movies', function (Blueprint $table): void {
            $table->dropColumn('embedding');
        });

        Schema::table('indexed_series', function (Blueprint $table): void {
            $table->dropColumn('embedding');
        });
    }
};
