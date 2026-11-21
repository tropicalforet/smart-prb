<?php

namespace App\Console\Commands;

use App\Models\PasienPrb;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BakukanFktp extends Command
{
    /**
     * Ganti nama FKTP tersimpan (singkatan apotek: KPMA, B SBY, IBRA, ...)
     * menjadi nama lengkap 8 FKTP penelitian, dan keluarkan data dengan FKTP
     * di luar 8 FKTP (mis. "Cijagra") beserta histori & tindak lanjutnya.
     * Keduanya dicatat di Audit Log. Aman dijalankan ulang.
     *
     * Backup database dulu sebelum menjalankan tanpa --dry-run.
     */
    protected $signature = 'prb:bakukan-fktp {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Ganti singkatan FKTP dengan nama lengkap dan keluarkan FKTP di luar 8 FKTP penelitian';

    public function handle(AuditLogService $audit): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $perFktp = PasienPrb::query()
            ->select('fktp_asal', DB::raw('COUNT(*) as jumlah'), DB::raw('COUNT(DISTINCT noka) as pasien'))
            ->groupBy('fktp_asal')->orderByDesc('jumlah')->get();

        $ganti = [];   // nama lama → nama baru
        $keluar = [];  // nama yang dikeluarkan
        $baris = [];
        foreach ($perFktp as $f) {
            $baku = PasienPrb::bakukanFktp($f->fktp_asal);
            if ($baku === null) {
                $keluar[] = $f->fktp_asal;
                $tindakan = 'dikeluarkan (di luar 8 FKTP)';
            } elseif ($baku !== $f->fktp_asal) {
                $ganti[$f->fktp_asal] = $baku;
                $tindakan = "→ {$baku}";
            } else {
                $tindakan = 'sudah baku';
            }
            $baris[] = [$f->fktp_asal ?? '(kosong)', $f->jumlah, $f->pasien, $tindakan];
        }
        $this->table(['FKTP tersimpan', 'Data PRB', 'Pasien', 'Tindakan'], $baris);

        $dikeluarkan = PasienPrb::where(function ($q) use ($keluar) {
            $q->whereIn('fktp_asal', array_filter($keluar, fn ($k) => $k !== null));
            if (in_array(null, $keluar, true)) {
                $q->orWhereNull('fktp_asal');
            }
        })->orderBy('kode_pasien')->get();

        if ($isDryRun) {
            $this->warn('MODE DRY-RUN: tidak ada yang disimpan. ' . $dikeluarkan->count() . ' data akan dikeluarkan.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($ganti, $dikeluarkan) {
            foreach ($ganti as $lama => $baru) {
                PasienPrb::where('fktp_asal', $lama)->update(['fktp_asal' => $baru]);
            }
            if ($dikeluarkan->isNotEmpty()) {
                PasienPrb::whereKey($dikeluarkan->modelKeys())->delete();
            }
        });

        if ($ganti) {
            $audit->catat('pasien', 'bakukan_fktp',
                'Mengganti singkatan FKTP dengan nama lengkap 8 FKTP penelitian',
                null, null,
                array_map(fn ($lama, $baru) => ['kolom' => 'FKTP', 'lama' => $lama, 'baru' => $baru], array_keys($ganti), $ganti)
            );
        }
        if ($dikeluarkan->isNotEmpty()) {
            $audit->hapusMassal($dikeluarkan->count(), $dikeluarkan->map->identitas()->values()->all());
        }

        $this->info(count($ganti) . ' nama FKTP diganti, ' . $dikeluarkan->count() . ' data dikeluarkan. Sisa: '
            . PasienPrb::count() . ' data PRB (' . PasienPrb::jumlahPasienUnik(PasienPrb::pluck('noka')) . ' pasien).');

        return self::SUCCESS;
    }
}
