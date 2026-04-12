<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_type_configs', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('type')->unique();
            $blueprint->string('label');
            $blueprint->text('description')->nullable();
            $blueprint->boolean('requires_approval')->default(true);
            $blueprint->boolean('is_enabled')->default(true);
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_type_configs');
    }
};
