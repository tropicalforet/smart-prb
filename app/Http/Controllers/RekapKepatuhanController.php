<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

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
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', Carbon::today()->toDateString());

        $query = PasienPrb::query();

        // 1. Filter Database
        if ($filterFktp) {
            $query->where('fktp_asal', $filterFktp);
        }

        if ($filterPeriode) {
            $query->where(function ($q) use ($filterPeriode) {
                $q->whereHas('riwayatPengambilanObat', function ($qRiwayat) use ($filterPeriode) {
                    $qRiwayat->where('tanggal_pengambilan', 'like', $filterPeriode . '%')
                             ->orWhere('jadwal_target', 'like', $filterPeriode . '%');
                })->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
            });
        }

        $allPasien = $query->get()->map(function ($pasien) use ($tanggalEvaluasi) {
            $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
            return [
                'fktp' => $pasien->fktp_asal,
                'status_kepatuhan' => $kepatuhan['status']
            ];
        });

        // 2. Filter Kepatuhan (setelah perhitungan)
        if ($filterKepatuhan) {
            $allPasien = $allPasien->filter(function ($p) use ($filterKepatuhan) {
                return $p['status_kepatuhan'] === $filterKepatuhan;
            });
        }

        // 3. Agregasi Global
        $totalPasien = $allPasien->count();
        $totalPatuh = $allPasien->where('status_kepatuhan', 'Patuh')->count();
        $totalToleransi = $allPasien->where('status_kepatuhan', 'Dalam Toleransi')->count();
        $totalTidakPatuh = $allPasien->where('status_kepatuhan', 'Tidak Patuh')->count();
        $totalPasif = $allPasien->where('status_kepatuhan', 'Pasif')->count();

        // 4. Agregasi per FKTP
        $rekapFktpData = [];
        $grouped = $allPasien->groupBy('fktp');
        
        foreach ($grouped as $fktp => $patients) {
            $rekapFktpData[] = [
                'fktp' => $fktp ?: 'Tanpa FKTP',
                'total' => $patients->count(),
                'patuh' => $patients->where('status_kepatuhan', 'Patuh')->count(),
                'dalam_toleransi' => $patients->where('status_kepatuhan', 'Dalam Toleransi')->count(),
                'tidak_patuh' => $patients->where('status_kepatuhan', 'Tidak Patuh')->count(),
                'pasif' => $patients->where('status_kepatuhan', 'Pasif')->count(),
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

        $periodeOptions = collect(range(0, 5))->map(function ($i) {
            $date = Carbon::now()->subMonths($i);
            return [
                'value' => $date->format('Y-m'),
                'label' => $date->translatedFormat('F Y')
            ];
        })->toArray();

        return Inertia::render('Rekap/Index', [
            'summary' => [
                'total' => $totalPasien,
                'patuh' => $totalPatuh,
                'dalam_toleransi' => $totalToleransi,
                'tidak_patuh' => $totalTidakPatuh,
                'pasif' => $totalPasif,
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
}
