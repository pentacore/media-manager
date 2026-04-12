<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_type_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_type_configs');
    }
};
