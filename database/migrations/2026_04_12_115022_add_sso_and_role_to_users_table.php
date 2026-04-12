<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->string('sso_provider')->nullable()->after('email');
            $blueprint->string('sso_id')->nullable()->after('sso_provider');
            $blueprint->string('role')->default(UserRole::Viewer->value)->after('sso_id');
            $blueprint->string('avatar_url')->nullable()->after('role');
            $blueprint->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['sso_provider', 'sso_id', 'role', 'avatar_url']);
            $blueprint->string('password')->nullable(false)->change();
        });
    }
};
