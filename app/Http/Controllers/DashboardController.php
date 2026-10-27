<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    public function index(Request $request)
    {
        $tanggalEvaluasi = now()->toDateString();
        $filterFktp      = $request->input('filter_fktp', '');
        $filterPeriode   = $request->input('filter_periode', '');

        // ── Statistik utama (KPI cards + donut + bar chart) ──────────────
        $statistik = $this->kepatuhanService->hitungStatistikDashboard(
            $tanggalEvaluasi,
            $filterFktp,
            $filterPeriode
        );

        // ── Data tren kepatuhan per bulan (12 bulan terakhir) ────────────
        // Dinilai per siklus pasien dengan logika yang sama dengan KPI
        // periode, sehingga tren bulan X = KPI saat filter periode X.
        $trenKepatuhan = $this->kepatuhanService->hitungTrenKepatuhan($tanggalEvaluasi, $filterFktp);

        // ── Options filter ────────────────────────────────────────────────
        $fktpOptions = PasienPrb::select('fktp_asal')
            ->whereNotNull('fktp_asal')
            ->distinct()
            ->orderBy('fktp_asal')
            ->pluck('fktp_asal')
            ->toArray();

        $periodeOptions = $this->kepatuhanService->daftarPeriode();

        return Inertia::render('Dashboard', [
            'statistik'      => $statistik,
            'tanggalEvaluasi'=> $tanggalEvaluasi,
            'trenKepatuhan'  => $trenKepatuhan,
            'fktpOptions'    => $fktpOptions,
            'periodeOptions' => $periodeOptions,
            'filters'        => [
                'filter_fktp'    => $filterFktp,
                'filter_periode' => $filterPeriode,
            ],
        ]);
    }
}
