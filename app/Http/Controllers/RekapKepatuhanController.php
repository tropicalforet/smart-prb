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
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', Carbon::today()->toDateString());

        $query = PasienPrb::with([
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

        // 2. Filter Kepatuhan (setelah perhitungan)
        if ($filterKepatuhan) {
            $allPasien = $allPasien->filter(function ($p) use ($filterKepatuhan) {
                return $p['status_kepatuhan'] === $filterKepatuhan;
            });
        }

        // 3. Agregasi Global
        //
        // $totalPasien = seluruh evaluasi dalam scope filter yang aktif,
        // KECUALI 'Tidak Ada Transaksi' — pasien yang belum bergabung di
        // periode ini tidak boleh masuk sampel evaluasi sama sekali.
        // Memasukkan mereka akan menggelembungkan total dan mendistorsi
        // persentase kepatuhan.
        //
        // 'Belum Jatuh Tempo' TETAP dihitung dalam total — mereka adalah
        // siklus aktif yang sedang berjalan, bukan siklus yang "tidak ada".
        // Persentase kepatuhan dihitung terpisah dari denominator yang tepat
        // (hanya Patuh + Tidak Patuh sebagai yang sudah dapat dievaluasi final).
        $totalBelumJatuhTempo  = $allPasien->where('status_kepatuhan', 'Belum Jatuh Tempo')->count();
        $totalPatuh            = $allPasien->where('status_kepatuhan', 'Patuh')->count();
        $totalToleransi        = $allPasien->where('status_kepatuhan', 'Dalam Toleransi')->count();
        $totalTidakPatuh       = $allPasien->where('status_kepatuhan', 'Tidak Patuh')->count();
        $totalPasif            = $allPasien->where('status_kepatuhan', 'Pasif')->count();
        $totalTidakAdaTransaksi = $allPasien->where('status_kepatuhan', 'Tidak Ada Transaksi')->count();

        // Total = seluruh evaluasi kecuali 'Tidak Ada Transaksi'
        // agar konsisten dengan sum kolom Total di tabel FKTP.
        $totalPasien = $allPasien->count() - $totalTidakAdaTransaksi;
        // 4. Agregasi per FKTP
        $rekapFktpData = [];
        $grouped = $allPasien->groupBy('fktp');
        
        foreach ($grouped as $fktp => $patients) {
            // Kecualikan 'Tidak Ada Transaksi' dari total per FKTP
            // agar konsisten dengan total global di atas.
            $tidakAdaTransaksi = $patients->where('status_kepatuhan', 'Tidak Ada Transaksi')->count();
            $patuhFktp         = $patients->where('status_kepatuhan', 'Patuh')->count();
            $tidakPatuhFktp    = $patients->where('status_kepatuhan', 'Tidak Patuh')->count();
            $rekapFktpData[] = [
                'fktp'             => $fktp ?: 'Tanpa FKTP',
                'total'            => $patients->count() - $tidakAdaTransaksi,
                'patuh'            => $patuhFktp,
                'dalam_toleransi'  => $patients->where('status_kepatuhan', 'Dalam Toleransi')->count(),
                'tidak_patuh'      => $tidakPatuhFktp,
                'persentase_patuh' => KepatuhanService::persentasePatuh($patuhFktp, $tidakPatuhFktp),
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
        $periodeOptions = $this->kepatuhanService->daftarPeriode();

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
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', Carbon::today()->toDateString());

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
