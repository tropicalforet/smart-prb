<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * PERHATIAN: Seeder ini hanya boleh berjalan di environment
     * lokal dan testing. Diblokir secara eksplisit di production
     * agar data dummy tidak masuk ke database produksi.
     */
    public function run(): void
    {
        // Guard: tolak eksekusi di production
        if (app()->isProduction()) {
            $this->command->error(
                '[DatabaseSeeder] Seeder DIBLOKIR di environment production. ' .
                'Seeder ini hanya untuk pengembangan dan testing.'
            );
            return;
        }

        $this->call([
            PasienPrbSeeder::class,
        ]);
    }
}

