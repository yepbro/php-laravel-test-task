<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Демо-набор для локальной разработки (T-007).
     *
     * В production демо-сидер автоматически не запускается (требование T-007);
     * сам DemoOrdersSeeder при явном вызове — в т.ч. с --force — дополнительно
     * отказывает с ошибкой.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Production: демо-сидер пропущен (DemoOrdersSeeder доступен только вне production).');

            return;
        }

        $this->call([DemoOrdersSeeder::class]);
    }
}
