<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PecahDataPerDiagnosis extends Command
{
    /**
     * Pecah data PRB yang berisi beberapa diagnosis (mis. dari sel Excel
     * "HT, DM") menjadi satu data per diagnosis.
     *
     * Data asli mempertahankan diagnosis pertama; diagnosis lain menjadi data
     * baru dengan kode yang sama (ID_PRB sama, dibedakan diagnosis), profil &
     * jadwal yang sama, dan salinan histori pengambilan. Tindak lanjut tetap di data asli.
     * Diagnosis yang sudah punya data sendiri untuk NOKA itu dilewati.
     */
    protected $signature = 'prb:pecah-diagnosis {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Pecah data PRB berisi beberapa diagnosis menjadi satu data per diagnosis';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang diubah.');
        }

        $daftar = PasienPrb::where('kunci_diagnosis', 'like', '%|%')
            ->with('riwayatPengambilanObat')
            ->orderBy('kode_pasien')
            ->get();

        $dataBaru = 0;
        $dilewati = 0;

        foreach ($daftar as $asli) {
            $diagnosis = array_values(array_unique(array_filter((array) $asli->diagnosis_prb)));
            $utama     = array_shift($diagnosis);

            DB::transaction(function () use ($asli, $utama, $diagnosis, $isDryRun, &$dataBaru, &$dilewati) {
                foreach ($diagnosis as $d) {
                    if (PasienPrb::cariPerDiagnosis($asli->noka, [$d])) {
                        $dilewati++;
                        continue;
                    }
                    $dataBaru++;
                    if ($isDryRun) {
                        continue;
                    }

                    $baru = $asli->replicate(['kunci_diagnosis']);
                    $baru->diagnosis_prb = [$d];
                    $baru->save();

                    foreach ($asli->riwayatPengambilanObat as $r) {
                        $salinan = $r->replicate();
                        $salinan->pasien_prb_id = $baru->id;
                        if ($salinan->no_faktur) {
                            // No. Faktur harus unik → beri akhiran diagnosis
                            $salinan->no_faktur .= '-' . Str::slug($d);
                        }
                        $salinan->save();
                    }
                }

                if (!$isDryRun) {
                    $asli->update(['diagnosis_prb' => [$utama]]);
                }
            });
        }

        $this->table(['Keterangan', 'Jumlah'], [
            ['Data berisi beberapa diagnosis', $daftar->count()],
            ['Data baru (satu per diagnosis)', $dataBaru],
            ['Dilewati (diagnosis sudah punya data sendiri)', $dilewati],
        ]);
        $this->info($isDryRun ? 'Selesai (dry-run).' : 'Selesai. Data dipecah per diagnosis.');

        return self::SUCCESS;
    }
}
