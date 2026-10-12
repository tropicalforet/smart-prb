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

        $query = PasienPrb::with('riwayatPengambilanObat');

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

        // 2. Filter Kepatuhan (setelah perhitungan)
        if ($filterKepatuhan) {
            $allPasien = $allPasien->filter(function ($p) use ($filterKepatuhan) {
                return $p['status_kepatuhan'] === $filterKepatuhan;
            });
        }

        // 3. Agregasi Global
        //
        // $totalPasien = pasien yang siklusnya sudah dapat dievaluasi final.
        // TIDAK termasuk 'Belum Jatuh Tempo' karena siklus mereka belum melewati
        // batas toleransi H+3 sehingga belum dapat dinilai Patuh/Tidak Patuh.
        // 'Belum Diambil' (H..H+3) tetap masuk karena sudah jatuh tempo meskipun
        // masih dalam toleransi — mereka sudah dapat dipantau.
        $totalBelumJatuhTempo = $allPasien->where('status_kepatuhan', 'Belum Jatuh Tempo')->count();
        $totalPatuh           = $allPasien->where('status_kepatuhan', 'Patuh')->count();
        $totalToleransi       = $allPasien->where('status_kepatuhan', 'Belum Diambil')->count();
        $totalTidakPatuh      = $allPasien->where('status_kepatuhan', 'Tidak Patuh')->count();
        $totalPasif           = $allPasien->where('status_kepatuhan', 'Pasif')->count();

        // Denominator rekap: total minus yang belum masuk window penilaian
        $totalPasien = $allPasien->count() - $totalBelumJatuhTempo;
        // 4. Agregasi per FKTP
        $rekapFktpData = [];
        $grouped = $allPasien->groupBy('fktp');
        
        foreach ($grouped as $fktp => $patients) {
            $rekapFktpData[] = [
                'fktp'             => $fktp ?: 'Tanpa FKTP',
                'total'            => $patients->count(),
                'patuh'            => $patients->where('status_kepatuhan', 'Patuh')->count(),
                'dalam_toleransi'  => $patients->where('status_kepatuhan', 'Belum Diambil')->count(),
                'tidak_patuh'      => $patients->where('status_kepatuhan', 'Tidak Patuh')->count(),
                'pasif'            => $patients->where('status_kepatuhan', 'Pasif')->count(),
                'belum_jatuh_tempo'=> $patients->where('status_kepatuhan', 'Belum Jatuh Tempo')->count(),
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
        $periodesJadwalTarget = \App\Models\RiwayatPengambilanObat::select('jadwal_target')
            ->whereNotNull('jadwal_target')
            ->pluck('jadwal_target')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'));

        $periodesMaster = PasienPrb::select('tanggal_jadwal_berikutnya')
            ->whereNotNull('tanggal_jadwal_berikutnya')
            ->where('status', 'Aktif')
            ->pluck('tanggal_jadwal_berikutnya')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'));

        $periodeOptions = $periodesJadwalTarget
            ->merge($periodesMaster)
            ->filter()
            ->unique()
            ->sortDesc()
            ->map(function ($period) {
                return [
                    'value' => $period,
                    'label' => Carbon::parse($period . '-01')->translatedFormat('F Y'),
                ];
            })->values()->toArray();

        return Inertia::render('Rekap/Index', [
            'summary' => [
                'total'             => $totalPasien,
                'patuh'             => $totalPatuh,
                'dalam_toleransi'   => $totalToleransi,
                'tidak_patuh'       => $totalTidakPatuh,
                'pasif'             => $totalPasif,
                // Pasien belum melewati batas penilaian (sebelum H-3)
                // Tidak masuk denominator persentase kepatuhan final.
                'belum_jatuh_tempo' => $totalBelumJatuhTempo,
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
