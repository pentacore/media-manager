<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;

class AiModelPriceSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 0.40, 'output_per_mtok' => 1.60, 'cache_read_per_mtok' => 0.10, 'cache_write_per_mtok' => 0.40, 'reasoning_per_mtok' => 1.60],
            ['provider' => 'openai', 'model' => 'gpt-5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 30.00, 'cache_read_per_mtok' => 1.25, 'cache_write_per_mtok' => 5.00, 'reasoning_per_mtok' => 30.00],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_per_mtok' => 1.00, 'output_per_mtok' => 5.00, 'cache_read_per_mtok' => 0.10, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'cache_read_per_mtok' => 0.30, 'cache_write_per_mtok' => 3.75, 'reasoning_per_mtok' => 0],
            ['provider' => 'anthropic', 'model' => 'claude-opus-4-6', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_read_per_mtok' => 0.50, 'cache_write_per_mtok' => 6.25, 'reasoning_per_mtok' => 0],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'input_per_mtok' => 0.30, 'output_per_mtok' => 2.50, 'cache_read_per_mtok' => 0.075, 'cache_write_per_mtok' => 0.30, 'reasoning_per_mtok' => 2.50],
            ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'input_per_mtok' => 0.50, 'output_per_mtok' => 3.00, 'cache_read_per_mtok' => 0.125, 'cache_write_per_mtok' => 0.50, 'reasoning_per_mtok' => 3.00],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-pro', 'input_per_mtok' => 1.25, 'output_per_mtok' => 10.00, 'cache_read_per_mtok' => 0.31, 'cache_write_per_mtok' => 1.25, 'reasoning_per_mtok' => 10.00],
        ];

        foreach ($defaults as $default) {
            AiModelPrice::updateOrCreate(
                ['provider' => $default['provider'], 'model' => $default['model']],
                $default,
            );
        }
    }
}
