<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Buat akun admin awal.
     *
     * Kredensial diambil dari .env:
     *   ADMIN_EMAIL    (default: admin@prbkesehatan.id)
     *   ADMIN_PASSWORD (jika kosong → dibuat acak dan ditampilkan SEKALI di konsol)
     *
     * Jika akun dengan email tersebut sudah ada, seeder TIDAK mengubah
     * password-nya — aman dijalankan ulang tanpa me-reset password yang
     * sudah diganti admin lewat halaman Ganti Password.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@prbkesehatan.id');

        if (User::where('email', $email)->exists()) {
            $this->command->info("Akun admin {$email} sudah ada — password tidak diubah.");
            return;
        }

        $password  = env('ADMIN_PASSWORD');
        $dariEnv   = !empty($password);
        $password  = $dariEnv ? $password : Str::password(14, symbols: false);

        User::create([
            'name'     => env('ADMIN_NAME', 'Admin'),
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        if ($dariEnv) {
            $this->command->info("Akun admin {$email} dibuat dengan password dari ADMIN_PASSWORD.");
        } else {
            $this->command->warn("Akun admin {$email} dibuat dengan password acak: {$password}");
            $this->command->warn('Catat sekarang — password ini tidak ditampilkan lagi. Segera ganti lewat menu Ganti Password.');
        }
    }
}
