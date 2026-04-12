<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_provider')->nullable()->after('email');
            $table->string('sso_id')->nullable()->after('sso_provider');
            $table->string('role')->default(UserRole::Viewer->value)->after('sso_id');
            $table->string('avatar_url')->nullable()->after('role');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sso_provider', 'sso_id', 'role', 'avatar_url']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
