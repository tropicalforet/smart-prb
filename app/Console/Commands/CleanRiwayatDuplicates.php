<?php

namespace App\Console\Commands;

use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanRiwayatDuplicates extends Command
{
    protected $signature = 'riwayat:clean-duplicates';
    protected $description = 'Hapus duplikat riwayat pengambilan obat dan perbaiki status kepatuhan';

    public function handle(): int
    {
        $this->info('=== Membersihkan duplikat riwayat dan memperbaiki status ===');

        // -------------------------------------------------------
        // 1. HAPUS DUPLIKAT BERDASARKAN NO. FAKTUR
        // -------------------------------------------------------
        $this->info('');
        $this->info('1. Mencari duplikat berdasarkan No. Faktur...');

        $duplikatFaktur = DB::table('riwayat_pengambilan_obat')
            ->select('no_faktur', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('no_faktur')
            ->where('no_faktur', '!=', '')
            ->groupBy('no_faktur')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        $deletedFaktur = 0;
        foreach ($duplikatFaktur as $dup) {
            $deleted = RiwayatPengambilanObat::where('no_faktur', $dup->no_faktur)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
            $deletedFaktur += $deleted;
        }
        $this->info("   Duplikat No. Faktur dihapus: {$deletedFaktur}");

        // -------------------------------------------------------
        // 2. HAPUS DUPLIKAT BERDASARKAN NOKA + TANGGAL BEROBAT
        //    (untuk record tanpa No. Faktur)
        // -------------------------------------------------------
        $this->info('');
        $this->info('2. Mencari duplikat berdasarkan NOKA + Tanggal Berobat (tanpa No. Faktur)...');

        $duplikatBpjsTgl = DB::table('riwayat_pengambilan_obat')
            ->join('pasien_prb', 'riwayat_pengambilan_obat.pasien_prb_id', '=', 'pasien_prb.id')
            ->select(
                'pasien_prb.noka',
                'riwayat_pengambilan_obat.tanggal_berobat',
                DB::raw('COUNT(*) as total'),
                DB::raw('MIN(riwayat_pengambilan_obat.id) as keep_id')
            )
            ->where(function ($q) {
                $q->whereNull('riwayat_pengambilan_obat.no_faktur')
                  ->orWhere('riwayat_pengambilan_obat.no_faktur', '');
            })
            ->whereNotNull('riwayat_pengambilan_obat.tanggal_berobat')
            ->groupBy('pasien_prb.noka', 'riwayat_pengambilan_obat.tanggal_berobat')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        $deletedNoka = 0;
        foreach ($duplikatBpjsTgl as $dup) {
            // Cari semua ID untuk kombinasi ini
            $allIds = DB::table('riwayat_pengambilan_obat')
                ->join('pasien_prb', 'riwayat_pengambilan_obat.pasien_prb_id', '=', 'pasien_prb.id')
                ->where('pasien_prb.noka', $dup->noka)
                ->where('riwayat_pengambilan_obat.tanggal_berobat', $dup->tanggal_berobat)
                ->where(function ($q) {
                    $q->whereNull('riwayat_pengambilan_obat.no_faktur')
                      ->orWhere('riwayat_pengambilan_obat.no_faktur', '');
                })
                ->where('riwayat_pengambilan_obat.id', '!=', $dup->keep_id)
                ->pluck('riwayat_pengambilan_obat.id');

            $deleted = RiwayatPengambilanObat::whereIn('id', $allIds)->delete();
            $deletedNoka += $deleted;
        }
        $this->info("   Duplikat NOKA+Tgl Berobat dihapus: {$deletedNoka}");

        // -------------------------------------------------------
        // 3. PERBAIKI STATUS KEPATUHAN SEMUA RIWAYAT
        // -------------------------------------------------------
        $this->info('');
        $this->info('3. Memperbaiki status kepatuhan semua riwayat...');

        $allRiwayat = RiwayatPengambilanObat::all();
        $fixed = 0;

        foreach ($allRiwayat as $riwayat) {
            if (!$riwayat->tanggal_pengambilan || !$riwayat->jadwal_target) {
                continue;
            }

            $tglPengambilan = Carbon::parse($riwayat->tanggal_pengambilan)->startOfDay();
            $jadwalTarget = Carbon::parse($riwayat->jadwal_target)->startOfDay();

            // Selisih: positif = terlambat, negatif = lebih awal
            $selisihHari = (int) $jadwalTarget->diffInDays($tglPengambilan, false);

            // Toleransi H-3 s.d. H+3
            $batasAwal = $jadwalTarget->copy()->subDays(3);
            $batasAkhir = $jadwalTarget->copy()->addDays(3);
            $statusBaru = $tglPengambilan->between($batasAwal, $batasAkhir, true)
                ? 'Patuh'
                : 'Tidak Teratur';

            if ($riwayat->selisih_hari !== $selisihHari || $riwayat->status_kepatuhan !== $statusBaru) {
                $riwayat->update([
                    'selisih_hari' => $selisihHari,
                    'status_kepatuhan' => $statusBaru,
                ]);
                $fixed++;
            }
        }
        $this->info("   Riwayat yang diperbaiki: {$fixed}");

        // -------------------------------------------------------
        // SUMMARY
        // -------------------------------------------------------
        $this->info('');
        $this->info('=== Selesai ===');
        $this->info("Total duplikat dihapus: " . ($deletedFaktur + $deletedNoka));
        $this->info("Total status diperbaiki: {$fixed}");
        $this->info("Sisa record riwayat: " . RiwayatPengambilanObat::count());

        return Command::SUCCESS;
    }
}
