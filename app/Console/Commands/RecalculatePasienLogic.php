<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculatePasienLogic extends Command
{
    protected $signature = 'pasien:recalculate-logic
                            {--dry-run : Tampilkan perubahan tanpa menyimpan}
                            {--clean-duplicates : Hapus riwayat duplikat (pasien + tanggal_pengambilan sama)}
                            {--delete-riwayat= : Hapus riwayat berdasarkan ID (pisah koma), lalu recalculate}';

    protected $description = 'Recalculate jenis_prb, jatah_obat, tanggal_pengambilan_terakhir, dan tanggal_jadwal_berikutnya semua pasien berdasarkan riwayat aktual.';

    public function handle()
    {
        $dryRun          = $this->option('dry-run');
        $cleanDuplicates = $this->option('clean-duplicates');
        $deleteIds       = $this->option('delete-riwayat');

        $this->info('=================================================');
        $this->info('  RECALCULATE LOGIKA PRB' . ($dryRun ? ' [DRY RUN]' : ''));
        $this->info('=================================================');

        // -------------------------------------------------------
        // LANGKAH 1 (opsional): Hapus riwayat berdasarkan ID eksplisit
        //
        // Digunakan untuk menghapus transaksi testing yang sudah
        // diidentifikasi sebelumnya. Lebih aman daripada heuristic
        // "tanpa faktur = test" yang bisa salah hapus data valid.
        // -------------------------------------------------------
        if ($deleteIds) {
            $this->info("\n--- LANGKAH 1: Hapus riwayat berdasarkan ID eksplisit ---");
            $idList = array_map('trim', explode(',', $deleteIds));

            foreach ($idList as $id) {
                $r = RiwayatPengambilanObat::find($id);
                if (!$r) {
                    $this->warn("  [SKIP] ID tidak ditemukan: {$id}");
                    continue;
                }

                $pasien = PasienPrb::find($r->pasien_prb_id);
                $namaPasien = $pasien ? "{$pasien->kode_pasien} ({$pasien->nama_pasien})" : $r->pasien_prb_id;

                $this->line("  Hapus riwayat {$id}");
                $this->line("    Pasien   : {$namaPasien}");
                $this->line("    Ambil    : " . ($r->tanggal_pengambilan ?? '-'));
                $this->line("    Target   : " . ($r->jadwal_target ?? '-'));
                $this->line("    Faktur   : " . ($r->no_faktur ?? '-KOSONG-'));
                $this->line("    Selisih  : {$r->selisih_hari} hari | Status: {$r->status_kepatuhan}");

                if (!$dryRun) {
                    $r->delete();
                    $this->info("    → Dihapus.");
                } else {
                    $this->comment("    → [DRY RUN] Tidak dihapus.");
                }
            }
        }

        // -------------------------------------------------------
        // LANGKAH 2 (opsional): Hapus riwayat duplikat
        //
        // Kriteria duplikat: pasien_prb_id + tanggal_pengambilan sama.
        // Simpan yang paling awal dibuat (created_at terkecil).
        // -------------------------------------------------------
        if ($cleanDuplicates) {
            $this->info("\n--- LANGKAH 2: Hapus riwayat duplikat ---");

            $duplicates = DB::select("
                SELECT pasien_prb_id, DATE(tanggal_pengambilan) as tgl, COUNT(*) as cnt,
                       GROUP_CONCAT(id ORDER BY created_at ASC SEPARATOR ',') as ids
                FROM riwayat_pengambilan_obat
                GROUP BY pasien_prb_id, DATE(tanggal_pengambilan)
                HAVING COUNT(*) > 1
            ");

            $deletedCount = 0;
            foreach ($duplicates as $dup) {
                $idList = explode(',', $dup->ids);
                $keepId = array_shift($idList);
                $deleteIds_dup = $idList;

                $pasien = PasienPrb::find($dup->pasien_prb_id);
                $nama = $pasien ? "{$pasien->kode_pasien} ({$pasien->nama_pasien})" : $dup->pasien_prb_id;

                $this->line("  {$nama}: {$dup->cnt} entri pada {$dup->tgl}, simpan 1, hapus " . count($deleteIds_dup));

                if (!$dryRun) {
                    RiwayatPengambilanObat::whereIn('id', $deleteIds_dup)->delete();
                }
                $deletedCount += count($deleteIds_dup);
            }
            $this->info("  Total dihapus: {$deletedCount} entri duplikat.");
        }

        // -------------------------------------------------------
        // LANGKAH 3: Recalculate field master semua pasien
        //
        // ATURAN (berlaku untuk semua pasien tanpa pengecualian):
        //
        // 1. Ambil riwayat valid: semua riwayat yang ada
        //    tanggal_pengambilan-nya, diurutkan DESC.
        //
        // 2. Jika ada riwayat valid:
        //    - jenis_prb                    = Lama
        //    - jatah_obat                   = 30
        //    - tanggal_pengambilan_terakhir = tanggal_pengambilan riwayat terbaru
        //    - tanggal_jadwal_berikutnya    = terakhir + 30 hari
        //    - tanggal_realisasi            = null (reset, sudah masuk riwayat)
        //
        // 3. Jika tidak ada riwayat valid:
        //    - jenis_prb                    = Baru
        //    - jatah_obat                   = 23
        //    - tanggal_pengambilan_terakhir = NULL (wajib null, bukan nilai lama)
        //    - tanggal_jadwal_berikutnya    = tanggal_mulai_prb + 23 hari
        //    - tanggal_realisasi            = null
        //
        // PENTING: tanggal_pengambilan_terakhir harus ikut di-null-kan
        // ketika riwayat kosong. Tanpa ini, master bisa menunjukkan
        // "Terakhir Ambil=28/08" padahal riwayat tidak ada sama sekali,
        // menyebabkan jenis=Baru tapi terakhir_master bernilai (inkonsisten).
        // -------------------------------------------------------
        $this->info("\n--- LANGKAH 3: Recalculate semua pasien ---");

        $pasiens = PasienPrb::with([
            'riwayatPengambilanObat' => fn($q) => $q->orderBy('tanggal_pengambilan', 'desc'),
        ])->orderBy('kode_pasien')->get();

        $updated = 0;
        $noChange = 0;

        foreach ($pasiens as $pasien) {
            $riwayatValid = $pasien->riwayatPengambilanObat
                ->filter(fn($r) => !empty($r->tanggal_pengambilan));

            $hasHistory      = $riwayatValid->isNotEmpty();
            $riwayatTerakhir = $riwayatValid->first(); // sudah sortDesc

            if ($hasHistory) {
                $newJenis       = 'Lama';
                $newJatah       = 30;
                $newTglTerakhir = Carbon::parse($riwayatTerakhir->tanggal_pengambilan)->toDateString();
                $newJadwal      = Carbon::parse($newTglTerakhir)->addDays(30)->toDateString();
                $newRealisasi   = null; // field master di-reset karena sudah ada di riwayat
            } else {
                // PRB Baru: tidak ada riwayat sama sekali.
                // tanggal_pengambilan_terakhir HARUS null — walaupun field master
                // sebelumnya bernilai (misalnya sisa dari data sebelumnya yang
                // riwayatnya sudah dihapus). Tanpa ini, master bisa menampilkan
                // "Terakhir Ambil=28/08" padahal riwayat kosong → inkonsistensi.
                $newJenis       = 'Baru';
                $newJatah       = 23;
                $newTglTerakhir = null; // wajib null untuk PRB Baru tanpa riwayat
                $tglMulai       = $pasien->tanggal_mulai_prb
                    ? Carbon::parse($pasien->tanggal_mulai_prb)->toDateString()
                    : Carbon::today()->toDateString();
                $newJadwal      = Carbon::parse($tglMulai)->addDays(23)->toDateString();
                $newRealisasi   = null;
            }

            // Bandingkan dengan nilai master saat ini
            $oldJenis       = $pasien->jenis_prb;
            $oldJatah       = (int) $pasien->jatah_obat;
            $oldTglTerakhir = $pasien->tanggal_pengambilan_terakhir
                ? Carbon::parse($pasien->tanggal_pengambilan_terakhir)->toDateString()
                : null;
            $oldJadwal = $pasien->tanggal_jadwal_berikutnya
                ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->toDateString()
                : null;

            $changes = [];
            if ($oldJenis       !== $newJenis)       $changes['jenis_prb']                    = "{$oldJenis} → {$newJenis}";
            if ($oldJatah       !== $newJatah)       $changes['jatah_obat']                   = "{$oldJatah} → {$newJatah}";
            if ($oldTglTerakhir !== $newTglTerakhir) $changes['tanggal_pengambilan_terakhir'] = ($oldTglTerakhir ?? 'NULL') . " → " . ($newTglTerakhir ?? 'NULL');
            if ($oldJadwal      !== $newJadwal)      $changes['tanggal_jadwal_berikutnya']    = ($oldJadwal ?? 'NULL') . " → {$newJadwal}";

            if (!empty($changes)) {
                $this->info("  {$pasien->kode_pasien} | {$pasien->nama_pasien}:");
                foreach ($changes as $field => $val) {
                    $this->line("    {$field}: {$val}");
                }
                $this->line("    Jumlah riwayat valid: " . $riwayatValid->count());

                if (!$dryRun) {
                    $pasien->update([
                        'jenis_prb'                    => $newJenis,
                        'jatah_obat'                   => $newJatah,
                        'tanggal_pengambilan_terakhir' => $newTglTerakhir,
                        'tanggal_jadwal_berikutnya'    => $newJadwal,
                        'tanggal_realisasi'            => $newRealisasi,
                    ]);
                }
                $updated++;
            } else {
                $noChange++;
                $this->line("  {$pasien->kode_pasien} | {$pasien->nama_pasien}: OK (tidak ada perubahan)");
            }
        }

        $this->info("\n=================================================");
        $this->info("  SELESAI" . ($dryRun ? ' [DRY RUN — tidak ada yang disimpan]' : ''));
        $this->info("  Pasien diperbarui : {$updated}");
        $this->info("  Tidak ada ubahan  : {$noChange}");
        $this->info("=================================================");
    }
}
