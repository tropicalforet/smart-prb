<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HitungUlangJenisPrb extends Command
{
    /**
     * Hitung ulang jenis PRB (Baru/Lama), jatah obat, dan jadwal berikutnya
     * semua pasien dengan aturan terbaru KepatuhanService::tentukanJenisPrb().
     *
     * Dipakai setelah aturan berubah — mis. arahan penelitian: pasien tanpa
     * tanggal mulai PRB yang pertama kali ambil obat di bulan terakhir
     * periode penelitian (Mei) adalah PRB Baru (jatah 23 hari).
     *
     * Acuan jadwal = pengambilan terakhir. Pasien yang belum pernah ambil obat
     * dan riwayat pengambilan tidak diubah.
     */
    protected $signature = 'prb:hitung-ulang-jenis {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Hitung ulang jenis PRB, jatah obat, dan jadwal berikutnya semua pasien';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang diubah.');
        }

        $diubah = ['Lama → Baru' => 0, 'Baru → Lama' => 0, 'Jadwal saja' => 0];

        PasienPrb::query()->orderBy('kode_pasien')->each(function (PasienPrb $pasien) use ($isDryRun, &$diubah) {
            $terakhir = $pasien->tanggal_pengambilan_terakhir?->toDateString();
            $mulai    = $pasien->tanggal_mulai_prb?->toDateString();

            // Belum pernah ambil obat → jenis & jadwal dari saat didaftarkan tetap dipakai
            if (!$terakhir) {
                return;
            }

            $jenis = KepatuhanService::tentukanJenisPrb(
                $mulai,
                $terakhir,
                KepatuhanService::tanggalAmbilPertama($pasien, $terakhir)
            );
            $jatah  = $jenis === 'Baru' ? KepatuhanService::JATAH_PRB_BARU : KepatuhanService::JATAH_PRB_LAMA;
            $jadwal = Carbon::parse($terakhir)->addDays($jatah)->toDateString();

            $jadwalLama = $pasien->tanggal_jadwal_berikutnya?->toDateString();
            if ($jenis === $pasien->jenis_prb && $jatah === (int) $pasien->jatah_obat && $jadwal === $jadwalLama) {
                return;
            }

            $kunci = $jenis !== $pasien->jenis_prb ? "{$pasien->jenis_prb} → {$jenis}" : 'Jadwal saja';
            $diubah[$kunci] = ($diubah[$kunci] ?? 0) + 1;

            if (!$isDryRun) {
                $pasien->update([
                    'jenis_prb'                 => $jenis,
                    'jatah_obat'                => $jatah,
                    'tanggal_jadwal_berikutnya' => $jadwal,
                ]);
            }
        });

        $this->table(['Perubahan', 'Jumlah pasien'], collect($diubah)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info($isDryRun ? 'Selesai (dry-run).' : 'Selesai. Data pasien diperbarui.');

        return self::SUCCESS;
    }
}
