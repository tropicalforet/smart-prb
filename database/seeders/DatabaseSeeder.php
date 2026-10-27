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
     * Akun admin selalu dibuat (aman dijalankan ulang: akun yang sudah
     * ada tidak diubah). Data pasien DUMMY hanya untuk lokal/testing —
     * diblokir di production agar tidak masuk ke database produksi.
     */
    public function run(): void
    {
        // Tanpa akun ini tidak ada yang bisa login ke instalasi baru
        $this->call(AdminUserSeeder::class);

        // Guard: tolak data dummy di production
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

