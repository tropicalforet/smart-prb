<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PetakanDiagnosis extends Command
{
    /**
     * Petakan diagnosis di luar 9 diagnosis PRB ke kategori PRB sesuai
     * notulensi meeting 15 November 2026 (CHF → Penyakit Jantung, GOUT →
     * Diabetes Melitus, BPH → Hipertensi, dst. — lihat PasienPrb::ALIAS_DIAGNOSIS).
     *
     *   --dari=FILE  Pulihkan dulu data diagnosis non-PRB yang pernah dihapus
     *                (prb:hapus-diagnosis-non-prb) dari salinan database lama,
     *                beserta histori pengambilan & tindak lanjutnya.
     *
     * Per data:
     *   - Pasien belum punya data dengan diagnosis hasil pemetaan → diagnosis
     *     data itu diganti (histori tetap).
     *   - Sudah punya (mis. GOUT → DM, padahal DM sudah ada) → digabung ke data
     *     yang ada: tindak lanjut dipindah, data ganda dihapus. Hanya bila semua
     *     tanggal pengambilannya sudah ada di data tujuan; selain itu dilewati
     *     dan dilaporkan agar dicek manual.
     *
     * Diagnosis yang tidak ada di pemetaan tidak disentuh.
     * Backup database dulu sebelum menjalankan tanpa --dry-run.
     */
    protected $signature = 'prb:petakan-diagnosis
        {--dari= : Salinan database lama untuk memulihkan data yang dihapus}
        {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Petakan diagnosis penyerta (CHF, GOUT, BPH, ...) ke 9 diagnosis PRB';

    public function handle(AuditLogService $audit): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $sumber   = $this->option('dari');

        if ($sumber && !is_file($sumber)) {
            $this->error("File tidak ditemukan: {$sumber}");
            return self::FAILURE;
        }

        $sebelum = PasienPrb::count();
        DB::beginTransaction();

        try {
            $dipulihkan = $sumber ? $this->pulihkan($sumber) : 0;
            [$diganti, $digabung, $dilewati, $rincian] = $this->petakan();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($rincian) {
            $this->table(['Data', 'Diagnosis lama', 'Menjadi', 'Tindakan'], $rincian);
        }
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Data dipulihkan dari salinan lama', $dipulihkan],
            ['Diagnosis diganti', $diganti],
            ['Digabung ke data yang sudah ada', $digabung],
            ['Dilewati (cek manual)', $dilewati],
            ['Data PRB sebelum → sesudah', $sebelum . ' → ' . PasienPrb::count()],
            ['Pasien unik (NOKA) sesudah', PasienPrb::jumlahPasienUnik(PasienPrb::pluck('noka'))],
        ]);

        if ($isDryRun) {
            DB::rollBack();
            $this->warn('MODE DRY-RUN: tidak ada yang disimpan.');
            return self::SUCCESS;
        }

        DB::commit();

        if ($rincian || $dipulihkan) {
            $audit->catat('pasien', 'petakan_diagnosis',
                "Memetakan diagnosis penyerta ke 9 diagnosis PRB ({$dipulihkan} dipulihkan, {$diganti} diganti, {$digabung} digabung)",
                null, null,
                array_map(fn ($r) => ['kolom' => $r[0], 'lama' => $r[1], 'baru' => "{$r[2]} ({$r[3]})"], $rincian)
            );
        }
        $this->info('Selesai.');

        return self::SUCCESS;
    }

    /** Salin data diagnosis non-PRB yang tidak ada lagi di database ini. */
    private function pulihkan(string $file): int
    {
        config(['database.connections.salinan_lama' => [
            'driver' => 'sqlite', 'database' => $file, 'foreign_key_constraints' => false,
        ]]);
        $lama = DB::connection('salinan_lama');

        $kolom = fn (string $tabel) => array_values(array_intersect(
            Schema::getColumnListing($tabel),
            Schema::connection('salinan_lama')->getColumnListing($tabel)
        ));
        [$kPasien, $kRiwayat, $kTl] = [$kolom('pasien_prb'), $kolom('riwayat_pengambilan_obat'), $kolom('riwayat_tindak_lanjut')];

        $adaSekarang = PasienPrb::pluck('id')->flip();
        $jumlah = 0;

        foreach ($lama->table('pasien_prb')->get() as $p) {
            $diagnosis = array_filter((array) json_decode($p->diagnosis_prb ?? '[]', true));
            $nonPrb      = $diagnosis && array_diff($diagnosis, PasienPrb::DIAGNOSIS_OPTIONS);
            $bisaDipetakan = $diagnosis && !in_array(null, array_map([PasienPrb::class, 'bakukanDiagnosis'], $diagnosis), true);
            // Hanya data non-PRB yang bisa dipetakan (mis. "HY" tetap di luar penelitian)
            if (!$nonPrb || !$bisaDipetakan || isset($adaSekarang[$p->id])) {
                continue;
            }
            // Diagnosis hasil pemetaan sudah ada untuk NOKA ini & tidak ada tindak
            // lanjut yang perlu dipindah → tidak perlu dipulihkan (akan digabung).
            $kunciBaru = PasienPrb::kunciDiagnosis(array_map([PasienPrb::class, 'bakukanDiagnosis'], $diagnosis));
            if (PasienPrb::where('noka', $p->noka)->where('kunci_diagnosis', $kunciBaru)->exists()
                && !$lama->table('riwayat_tindak_lanjut')->where('pasien_prb_id', $p->id)->exists()) {
                continue;
            }

            DB::table('pasien_prb')->insert(array_intersect_key((array) $p, array_flip($kPasien)));
            foreach (['riwayat_pengambilan_obat' => $kRiwayat, 'riwayat_tindak_lanjut' => $kTl] as $tabel => $k) {
                foreach ($lama->table($tabel)->where('pasien_prb_id', $p->id)->get() as $r) {
                    DB::table($tabel)->insert(array_intersect_key((array) $r, array_flip($k)));
                }
            }
            $jumlah++;
        }

        return $jumlah;
    }

    /** @return array{int, int, int, array<int, array<int, string>>} */
    private function petakan(): array
    {
        $diganti = $digabung = $dilewati = 0;
        $rincian = [];

        $daftar = PasienPrb::with('riwayatPengambilanObat')->orderBy('kode_pasien')->get()
            ->filter(fn (PasienPrb $p) => collect((array) $p->diagnosis_prb)
                ->contains(fn ($d) => !in_array($d, PasienPrb::DIAGNOSIS_OPTIONS, true)));

        foreach ($daftar as $p) {
            $lama = (array) $p->diagnosis_prb;
            $baru = array_map(fn ($d) => PasienPrb::bakukanDiagnosis($d), $lama);
            if (in_array(null, $baru, true)) {
                continue; // ada diagnosis yang tidak bisa dipetakan → biarkan
            }
            $baru = array_values(array_unique($baru));
            $label = "{$p->kode_pasien} (NOKA {$p->noka})";

            $tujuan = PasienPrb::where('noka', $p->noka)
                ->where('kunci_diagnosis', PasienPrb::kunciDiagnosis($baru))
                ->where('id', '!=', $p->id)
                ->with('riwayatPengambilanObat')
                ->first();

            if (!$tujuan) {
                $p->update(['diagnosis_prb' => $baru]); // kunci_diagnosis ikut diperbarui (model)
                $diganti++;
                $rincian[] = [$label, implode(', ', $lama), implode(', ', $baru), 'diganti'];
                continue;
            }

            $tanggalTujuan = $tujuan->riwayatPengambilanObat
                ->map(fn ($r) => $r->tanggal_pengambilan?->toDateString())->filter()->flip();
            $tanggalBaru = $p->riwayatPengambilanObat
                ->map(fn ($r) => $r->tanggal_pengambilan?->toDateString())->filter()
                ->reject(fn ($t) => isset($tanggalTujuan[$t]));

            if ($tanggalBaru->isNotEmpty()) {
                $dilewati++;
                $rincian[] = [$label, implode(', ', $lama), implode(', ', $baru),
                    'dilewati: tanggal ' . $tanggalBaru->implode(', ') . ' tidak ada di data ' . $tujuan->kode_pasien];
                continue;
            }

            RiwayatTindakLanjut::where('pasien_prb_id', $p->id)->update(['pasien_prb_id' => $tujuan->id]);
            $p->delete(); // histori pengambilan identik dengan data tujuan
            $digabung++;
            $rincian[] = [$label, implode(', ', $lama), implode(', ', $baru), 'digabung (sudah ada)'];
        }

        return [$diganti, $digabung, $dilewati, $rincian];
    }
}
