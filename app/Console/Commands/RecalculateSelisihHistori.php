<?php

namespace App\Console\Commands;

use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecalculateSelisihHistori extends Command
{
    /**
     * Recalculate selisih_hari dan status_kepatuhan pada semua riwayat pengambilan obat
     * berdasarkan tanggal_pengambilan vs jadwal_target yang tersimpan di transaksi itu sendiri.
     *
     * Aturan:
     * - selisih_hari = tanggal_pengambilan - jadwal_target
     *   (positif = terlambat, negatif = lebih awal, 0 = tepat waktu)
     *
     * - status_kepatuhan:
     *   H-3 s.d. H+3 → Patuh
     *   lebih awal dari H-3 → Tidak Patuh
     *   lebih lambat dari H+3 → Tidak Patuh
     *
     * CATATAN: Tidak mengubah jadwal_target. Hanya memperbaiki selisih_hari dan status_kepatuhan.
     */
    protected $signature = 'histori:recalculate-selisih {--dry-run : Preview tanpa menyimpan}';

    protected $description = 'Recalculate selisih_hari dan status_kepatuhan berdasarkan tanggal_pengambilan vs jadwal_target pada setiap transaksi';

    private const TOLERANSI = 3; // hari

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang diubah.');
        }

        $records = RiwayatPengambilanObat::whereNotNull('tanggal_pengambilan')
            ->whereNotNull('jadwal_target')
            ->get();

        $totalDiubah = 0;

        foreach ($records as $r) {
            $tglAmbil = Carbon::parse($r->tanggal_pengambilan)->startOfDay();
            $jadwal   = Carbon::parse($r->jadwal_target)->startOfDay();

            // selisih_hari = tglAmbil - jadwal
            $selisih = (int) $jadwal->diffInDays($tglAmbil, false);

            $batasAwal  = $jadwal->copy()->subDays(self::TOLERANSI);
            $batasAkhir = $jadwal->copy()->addDays(self::TOLERANSI);
            $status     = $tglAmbil->between($batasAwal, $batasAkhir, true)
                ? 'Patuh'
                : 'Tidak Patuh';

            $selisihLama = $r->selisih_hari;
            $statusLama  = $r->status_kepatuhan;

            if ($selisih !== $selisihLama || $status !== $statusLama) {
                $this->line(sprintf(
                    "[%s] No.Faktur: %-20s | Tgl Ambil: %s | Jadwal: %s | Selisih: %s→%s | Status: %s→%s",
                    $r->id,
                    $r->no_faktur ?? '(kosong)',
                    $r->tanggal_pengambilan->toDateString(),
                    $r->jadwal_target->toDateString(),
                    $selisihLama,
                    $selisih,
                    $statusLama,
                    $status
                ));

                if (!$isDryRun) {
                    $r->update([
                        'selisih_hari'     => $selisih,
                        'status_kepatuhan' => $status,
                    ]);
                }

                $totalDiubah++;
            }
        }

        if ($isDryRun) {
            $this->warn("Total record yang AKAN diubah: {$totalDiubah} dari {$records->count()} total.");
        } else {
            $this->info("Total record diperbarui: {$totalDiubah} dari {$records->count()} total.");
        }

        return Command::SUCCESS;
    }
}
