<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:refresh-prices')]
#[Description('Re-applies the bundled AI model price catalog so existing rows pick up rate changes.')]
class RefreshAiPrices extends Command
{
    public function handle(AiModelPriceSeeder $aiModelPriceSeeder): int
    {
        $aiModelPriceSeeder->setCommand($this)->run();

        $this->info('AI model prices refreshed from bundled catalog.');

        return self::SUCCESS;
    }
}
