<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class SalinDatabase extends Command
{
    /**
     * Salinan database SQLite yang konsisten untuk dipindah ke hosting.
     *
     * Memakai VACUUM INTO (aman walau aplikasi sedang berjalan), lalu
     * mengosongkan tabel sesi & cache di salinan. Data pasien, histori,
     * tindak lanjut, akun, dan Audit Log ikut tersalin apa adanya.
     *
     * File hasil berisi data pasien: jangan dimasukkan ke Git.
     */
    protected $signature = 'prb:salin-database {tujuan : Path file .sqlite hasil salinan} {--force : Timpa file tujuan bila sudah ada}';

    protected $description = 'Buat salinan database SQLite untuk dipindah ke server hosting';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->error('Perintah ini hanya untuk database SQLite.');
            return self::FAILURE;
        }

        $tujuan = $this->argument('tujuan');
        if (file_exists($tujuan)) {
            if (!$this->option('force')) {
                $this->error("File {$tujuan} sudah ada. Tambahkan --force untuk menimpa.");
                return self::FAILURE;
            }
            unlink($tujuan);
        }
        if (!is_dir(dirname($tujuan))) {
            mkdir(dirname($tujuan), 0775, true);
        }

        DB::statement('VACUUM INTO ?', [$tujuan]);

        $salinan = new PDO('sqlite:' . $tujuan);
        foreach (['sessions', 'cache', 'cache_locks'] as $tabel) {
            $ada = $salinan->query("select count(*) from sqlite_master where type='table' and name='{$tabel}'")->fetchColumn();
            if ($ada) {
                $salinan->exec("delete from {$tabel}");
            }
        }
        $salinan->exec('VACUUM');

        $hitung = fn (string $tabel) => (int) $salinan->query("select count(*) from {$tabel}")->fetchColumn();
        $this->table(['Isi salinan', 'Jumlah'], [
            ['Data PRB', $hitung('pasien_prb')],
            ['Histori pengambilan', $hitung('riwayat_pengambilan_obat')],
            ['Tindak lanjut', $hitung('riwayat_tindak_lanjut')],
            ['Akun', $hitung('users')],
        ]);
        $salinan = null;

        $this->info('Salinan dibuat: ' . realpath($tujuan) . ' (' . round(filesize($tujuan) / 1024) . ' KB)');
        $this->warn('Berisi data pasien — jangan dibagikan atau dimasukkan ke Git.');

        return self::SUCCESS;
    }
}
