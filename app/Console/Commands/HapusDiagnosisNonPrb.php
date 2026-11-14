<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Services\AuditLogService;
use Illuminate\Console\Command;

class HapusDiagnosisNonPrb extends Command
{
    /**
     * Keluarkan data PRB dengan diagnosis di luar 9 diagnosis PRB (mis. GOUT,
     * DLP, CHF dari file apotek). Histori pengambilan & tindak lanjutnya ikut
     * terhapus. Penghapusan dicatat di Audit Log.
     *
     * Backup database dulu sebelum menjalankan tanpa --dry-run.
     */
    protected $signature = 'prb:hapus-diagnosis-non-prb {--dry-run : Tampilkan data yang akan dihapus tanpa menghapus}';

    protected $description = 'Hapus data PRB dengan diagnosis di luar 9 diagnosis PRB';

    public function handle(AuditLogService $audit): int
    {
        $isDryRun = $this->option('dry-run');

        $daftar = PasienPrb::orderBy('kode_pasien')->get()->filter(function (PasienPrb $p) {
            $diagnosis = array_filter((array) $p->diagnosis_prb);

            return !$diagnosis
                || collect($diagnosis)->contains(fn ($d) => !in_array($d, PasienPrb::DIAGNOSIS_OPTIONS, true));
        });

        $perDiagnosis = $daftar->groupBy(fn (PasienPrb $p) => implode(', ', (array) $p->diagnosis_prb) ?: '(kosong)')
            ->map->count()->sortDesc();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada data dengan diagnosis di luar 9 diagnosis PRB.');
            return self::SUCCESS;
        }

        $this->table(['Diagnosis', 'Jumlah data'], $perDiagnosis->map(fn ($n, $d) => [$d, $n])->values()->all());

        if ($isDryRun) {
            $this->warn("MODE DRY-RUN: {$daftar->count()} data akan dihapus. Tidak ada yang diubah.");
            return self::SUCCESS;
        }

        $identitas = $daftar->map->identitas()->values()->all();
        PasienPrb::whereKey($daftar->modelKeys())->delete();
        $audit->hapusMassal(count($identitas), $identitas);

        $this->info("{$daftar->count()} data dihapus. Sisa: " . PasienPrb::count() . ' data PRB.');

        return self::SUCCESS;
    }
}
