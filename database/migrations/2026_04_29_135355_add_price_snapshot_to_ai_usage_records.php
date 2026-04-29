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
            // Per-million-token rates captured from ai_model_prices at the
            // moment the call was recorded. Nullable because (a) older rows
            // pre-date this column and (b) calls against unpriced models leave
            // the snapshot empty until an admin assigns one retroactively.
            $blueprint->decimal('input_per_mtok', 10, 4)->nullable()->after('reasoning_tokens');
            $blueprint->decimal('output_per_mtok', 10, 4)->nullable()->after('input_per_mtok');
            $blueprint->decimal('cache_read_per_mtok', 10, 4)->nullable()->after('output_per_mtok');
            $blueprint->decimal('cache_write_per_mtok', 10, 4)->nullable()->after('cache_read_per_mtok');
            $blueprint->decimal('reasoning_per_mtok', 10, 4)->nullable()->after('cache_write_per_mtok');
            // 'live' (captured at call time), 'assigned' (admin set
            // retroactively), or null (never priced). Lets the UI distinguish
            // provenance and call out retroactively-priced rows.
            $blueprint->string('price_source', 16)->nullable()->after('reasoning_per_mtok');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropColumn([
                'input_per_mtok',
                'output_per_mtok',
                'cache_read_per_mtok',
                'cache_write_per_mtok',
                'reasoning_per_mtok',
                'price_source',
            ]);
        });
    }
};
