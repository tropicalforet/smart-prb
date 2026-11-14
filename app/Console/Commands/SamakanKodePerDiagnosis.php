<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use Illuminate\Console\Command;

class SamakanKodePerDiagnosis extends Command
{
    /**
     * Kembalikan kode berakhiran (PRB-1215-2, PRB-1215-3) hasil pemecahan
     * per diagnosis ke kode asalnya (PRB-1215), karena ID_PRB di data apotek
     * sama untuk semua diagnosis satu pasien.
     *
     * Hanya diubah bila kode asal ada dan milik NOKA yang sama, sehingga kode
     * dari file yang memang mengandung tanda "-angka" tidak tersentuh.
     */
    protected $signature = 'prb:samakan-kode {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Samakan kode pasien berakhiran -2/-3 dengan kode asalnya (satu kode, beberapa diagnosis)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang diubah.');
        }

        $diubah = [];

        PasienPrb::where('kode_pasien', 'like', '%-%')->orderBy('kode_pasien')->each(function (PasienPrb $p) use ($isDryRun, &$diubah) {
            if (!preg_match('/^(.+)-(\d+)$/', $p->kode_pasien, $m) || (int) $m[2] < 2) {
                return;
            }

            $kodeAsal = $m[1];
            $asalSamaNoka = PasienPrb::where('kode_pasien', $kodeAsal)
                ->where('noka', $p->noka)
                ->where('id', '!=', $p->id)
                ->exists();

            if (!$asalSamaNoka) {
                return;
            }

            $diubah[] = [$p->kode_pasien, $kodeAsal, implode(', ', (array) $p->diagnosis_prb)];

            if (!$isDryRun) {
                // Langsung ke query: kode tidak dicatat sebagai perubahan profil
                PasienPrb::whereKey($p->id)->update(['kode_pasien' => $kodeAsal]);
            }
        });

        if ($diubah) {
            $this->table(['Kode lama', 'Kode baru', 'Diagnosis'], array_slice($diubah, 0, 20));
            if (count($diubah) > 20) {
                $this->line('... dan ' . (count($diubah) - 20) . ' lainnya.');
            }
        }

        $this->info(($isDryRun ? 'Akan diubah: ' : 'Diubah: ') . count($diubah) . ' data.');

        return self::SUCCESS;
    }
}
