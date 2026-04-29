<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (app()->environment(['local', 'testing'])) {
            User::factory()->admin()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call(ActionTypeConfigSeeder::class);
        $this->call(ServiceConnectionSeeder::class);
        $this->call(AiModelPriceSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoActivitySeeder::class);
        }
    }
}
