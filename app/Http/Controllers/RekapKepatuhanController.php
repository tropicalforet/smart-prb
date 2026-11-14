<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Services\KepatuhanService;
use App\Exports\RekapKepatuhanExport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class RekapKepatuhanController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    public function index(Request $request)
    {
        $filterPeriode = $request->input('filter_periode', '');
        $filterFktp = $request->input('filter_fktp', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        // Tanggal evaluasi kita default ke hari ini, atau bisa di set dari filter
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', KepatuhanService::tanggalEvaluasiBawaan());

        // Rekap hanya untuk pasien Aktif — pasien Pasif sudah keluar dari
        // pemantauan, jadi tidak ditampilkan maupun dihitung di total.
        $query = PasienPrb::aktif()->with([
            'riwayatPengambilanObat' => fn($q) =>
                $q->orderBy('tanggal_pengambilan', 'desc'),
        ]);

        // 1. Filter Database
        if ($filterFktp) {
            $query->where('fktp_asal', $filterFktp);
        }

        if ($filterPeriode) {
            $query->where(function ($q) use ($filterPeriode) {
                $q->whereHas('riwayatPengambilanObat', function ($qRiwayat) use ($filterPeriode) {
                    $qRiwayat->where('jadwal_target', 'like', $filterPeriode . '%');
                })->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
            });
        }

        $allPasien = $query->get()->map(function ($pasien) use ($tanggalEvaluasi, $filterPeriode) {
            if ($filterPeriode) {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $tanggalEvaluasi);
            } else {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
            }
            return [
                'fktp' => $pasien->fktp_asal,
                'status_kepatuhan' => $kepatuhan['status']
            ];
        });

        // Rekap hanya memuat pasien yang sudah dinilai (Patuh / Tidak Teratur).
        // Dalam Toleransi, Belum Jatuh Tempo, dan Tidak Ada Transaksi belum
        // atau tidak dapat dinilai, jadi tidak ditampilkan maupun dihitung.
        $allPasien = $allPasien->whereIn('status_kepatuhan', KepatuhanService::STATUS_DINILAI);

        // 2. Filter Kepatuhan (setelah perhitungan)
        if ($filterKepatuhan) {
            $allPasien = $allPasien->filter(function ($p) use ($filterKepatuhan) {
                return $p['status_kepatuhan'] === $filterKepatuhan;
            });
        }

        // 3. Agregasi Global — Total = Patuh + Tidak Teratur
        $totalPatuh      = $allPasien->where('status_kepatuhan', 'Patuh')->count();
        $totalTidakPatuh = $allPasien->where('status_kepatuhan', 'Tidak Teratur')->count();
        $totalPasien     = $allPasien->count();
        // 4. Agregasi per FKTP
        $rekapFktpData = [];
        $grouped = $allPasien->groupBy('fktp');
        
        foreach ($grouped as $fktp => $patients) {
            $patuhFktp      = $patients->where('status_kepatuhan', 'Patuh')->count();
            $tidakPatuhFktp = $patients->where('status_kepatuhan', 'Tidak Teratur')->count();
            $rekapFktpData[] = [
                'fktp'             => $fktp ?: 'Tanpa FKTP',
                'total'            => $patients->count(),
                'patuh'            => $patuhFktp,
                'tidak_patuh'      => $tidakPatuhFktp,
                'persentase_patuh' => KepatuhanService::persentasePatuh($patuhFktp, $tidakPatuhFktp),
            ];
        }

        // Urutkan berdasarkan total terbanyak
        usort($rekapFktpData, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        // Options
        $fktpOptions = PasienPrb::select('fktp_asal')
            ->whereNotNull('fktp_asal')
            ->distinct()
            ->pluck('fktp_asal')
            ->toArray();

        // ---------------------------------------------------------------
        // KUMPULKAN PERIODE DINAMIS — konsisten dengan PasienPrbController.
        //
        // 2 sumber (TIDAK dari tanggal_pengambilan riwayat):
        // 1. jadwal_target di riwayat — siklus ditentukan dari jadwal,
        //    bukan dari kapan pasien realisasi mengambil obat.
        // 2. tanggal_jadwal_berikutnya di master (pasien Aktif) — siklus
        //    yang sedang berjalan dan belum ada riwayatnya.
        // ---------------------------------------------------------------
        $periodeOptions = $this->kepatuhanService->daftarPeriode();

        return Inertia::render('Rekap/Index', [
            'summary' => [
                'total'             => $totalPasien,
                'patuh'             => $totalPatuh,
                'tidak_patuh'       => $totalTidakPatuh,
                'persentase_patuh'  => KepatuhanService::persentasePatuh($totalPatuh, $totalTidakPatuh),
            ],
            'rekapFktp' => $rekapFktpData,
            'filters' => [
                'filter_periode' => $filterPeriode,
                'filter_fktp' => $filterFktp,
                'filter_kepatuhan' => $filterKepatuhan,
                'tanggal_evaluasi' => $tanggalEvaluasi,
            ],
            'fktpOptions' => $fktpOptions,
            'periodeOptions' => $periodeOptions,
        ]);
    }

    /**
     * Export data rekap kepatuhan ke Excel
     */
    public function export(Request $request)
    {
        $filterPeriode = $request->input('filter_periode', '');
        $filterFktp = $request->input('filter_fktp', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', KepatuhanService::tanggalEvaluasiBawaan());

        // Generate filename dengan timestamp dan filter info
        $timestamp = Carbon::now()->format('Ymd_His');
        $filterInfo = [];
        
        if ($filterPeriode) {
            $periodeLabel = Carbon::parse($filterPeriode . '-01')->translatedFormat('F_Y');
            $filterInfo[] = $periodeLabel;
        }
        
        if ($filterFktp) {
            $filterInfo[] = str_replace(' ', '_', $filterFktp);
        }
        
        if ($filterKepatuhan) {
            $filterInfo[] = str_replace(' ', '_', $filterKepatuhan);
        }

        $filename = 'Rekap_Kepatuhan_PRB';
        
        if (!empty($filterInfo)) {
            $filename .= '_' . implode('_', $filterInfo);
        }
        
        $filename .= '_' . $timestamp . '.xlsx';

        return Excel::download(
            new RekapKepatuhanExport(
                $filterPeriode,
                $filterFktp,
                $filterKepatuhan,
                $tanggalEvaluasi
            ),
            $filename
        );
    }
}
