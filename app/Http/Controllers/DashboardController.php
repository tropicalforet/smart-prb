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
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', now()->toDateString());

        $statistik = $this->kepatuhanService->hitungStatistikDashboard($tanggalEvaluasi);

        // Ambil semua pasien aktif dengan kepatuhan terhitung
        $pasienAktif = PasienPrb::aktif()
            ->orderBy('tanggal_jadwal_berikutnya', 'asc')
            ->get()
            ->map(function ($pasien) use ($tanggalEvaluasi) {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                return array_merge($pasien->toArray(), [
                    'status_kepatuhan' => $kepatuhan['status'],
                    'selisih_hari_evaluasi' => $kepatuhan['selisih_hari'],
                    'keterangan_kepatuhan' => $kepatuhan['keterangan'],
                ]);
            });

        return Inertia::render('Dashboard', [
            'statistik' => $statistik,
            'pasienAktif' => $pasienAktif,
            'tanggalEvaluasi' => $tanggalEvaluasi,
        ]);
    }
}
