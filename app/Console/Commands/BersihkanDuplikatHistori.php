<?php

namespace App\Console\Commands;

use App\Models\RiwayatPengambilanObat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BersihkanDuplikatHistori extends Command
{
    /**
     * Hapus duplikat riwayat pengambilan obat.
     *
     * Aturan:
     * - Jika no_faktur tidak kosong: satu no_faktur = satu riwayat per pasien.
     * - Jika no_faktur kosong: kombinasi pasien_prb_id + tanggal_berobat = satu riwayat.
     *
     * Yang dipertahankan: record dengan id terkecil (paling lama dibuat).
     */
    protected $signature = 'histori:bersihkan-duplikat {--dry-run : Preview tanpa menghapus}';

    protected $description = 'Bersihkan duplikat riwayat pengambilan obat berdasarkan No. Faktur atau kombinasi BPJS + Tanggal Berobat';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang dihapus.');
        }

        $totalDihapus = 0;

        // ----------------------------------------------------------
        // 1. Duplikat berdasarkan no_faktur (tidak kosong)
        // ----------------------------------------------------------
        $duplikatFaktur = DB::select("
            SELECT MIN(id) as keep_id, no_faktur, COUNT(*) as jumlah
            FROM riwayat_pengambilan_obat
            WHERE no_faktur IS NOT NULL AND no_faktur != ''
            GROUP BY no_faktur
            HAVING COUNT(*) > 1
        ");

        foreach ($duplikatFaktur as $dup) {
            $idsToDelete = RiwayatPengambilanObat::where('no_faktur', $dup->no_faktur)
                ->where('id', '!=', $dup->keep_id)
                ->pluck('id');

            $count = $idsToDelete->count();

            $this->line("Duplikat No. Faktur [{$dup->no_faktur}]: {$dup->jumlah} record, hapus {$count}");

            if (!$isDryRun && $count > 0) {
                RiwayatPengambilanObat::whereIn('id', $idsToDelete)->delete();
            }

            $totalDihapus += $count;
        }

        // ----------------------------------------------------------
        // 2. Duplikat berdasarkan pasien_prb_id + tanggal_berobat
        //    (hanya untuk record tanpa no_faktur)
        // ----------------------------------------------------------
        $duplikatBpjs = DB::select("
            SELECT MIN(id) as keep_id, pasien_prb_id, tanggal_berobat, COUNT(*) as jumlah
            FROM riwayat_pengambilan_obat
            WHERE (no_faktur IS NULL OR no_faktur = '')
              AND tanggal_berobat IS NOT NULL
            GROUP BY pasien_prb_id, tanggal_berobat
            HAVING COUNT(*) > 1
        ");

        foreach ($duplikatBpjs as $dup) {
            $idsToDelete = RiwayatPengambilanObat::where('pasien_prb_id', $dup->pasien_prb_id)
                ->where('tanggal_berobat', $dup->tanggal_berobat)
                ->whereNull('no_faktur')
                ->where('id', '!=', $dup->keep_id)
                ->pluck('id');

            $count = $idsToDelete->count();

            $this->line("Duplikat Pasien [{$dup->pasien_prb_id}] Tgl Berobat [{$dup->tanggal_berobat}]: {$dup->jumlah} record, hapus {$count}");

            if (!$isDryRun && $count > 0) {
                RiwayatPengambilanObat::whereIn('id', $idsToDelete)->delete();
            }

            $totalDihapus += $count;
        }

        if ($isDryRun) {
            $this->warn("Total duplikat yang AKAN dihapus: {$totalDihapus}");
        } else {
            $this->info("Total duplikat dihapus: {$totalDihapus}");
        }

        return Command::SUCCESS;
    }
}
