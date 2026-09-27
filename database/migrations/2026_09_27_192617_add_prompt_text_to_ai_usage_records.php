<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            // The input half of response_text: the prompt an agent run was
            // sent, or a classification call's state and questions. Shown in
            // the AI Usage detail modal; the listeners cap it like the reply.
            $blueprint->longText('prompt_text')->nullable()->after('reasoning_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('prompt_text');
        });
    }
};
