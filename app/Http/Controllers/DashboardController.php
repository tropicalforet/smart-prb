<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /** Jumlah baris tabel "Pasien Tidak Patuh" di dashboard. */
    private const JUMLAH_PASIEN_TIDAK_PATUH = 10;

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

        // ── Pasien aktif untuk tabel overdue ─────────────────────────────
        // Eager load riwayat: dibutuhkan service untuk menghitung status;
        // tanpa ini setiap pasien memicu 1 query tambahan (N+1).
        $queryPasien = PasienPrb::aktif()
            ->with(['riwayatPengambilanObat' => fn ($q) => $q->orderBy('tanggal_pengambilan', 'desc')])
            ->orderBy('tanggal_jadwal_berikutnya', 'asc');

        if ($filterFktp) {
            $queryPasien->where('fktp_asal', $filterFktp);
        }

        // Tabel dashboard hanya menampilkan 10 pasien "Tidak Patuh" pertama
        // (urut jadwal terdekat). Saring di server dan berhenti begitu 10
        // ditemukan — jangan hitung & kirim seluruh pasien aktif ke browser.
        $pasienAktif = [];

        foreach ($queryPasien->get() as $pasien) {
            $kepatuhan = $filterPeriode
                ? $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $tanggalEvaluasi)
                : $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);

            if ($kepatuhan['status'] !== 'Tidak Patuh') {
                continue;
            }

            // withoutRelations: riwayat hanya untuk perhitungan,
            // tidak dipakai tabel dashboard → jangan ikut dikirim ke browser
            $pasienAktif[] = array_merge($pasien->withoutRelations()->toArray(), [
                'status_kepatuhan'      => $kepatuhan['status'],
                'selisih_hari_evaluasi' => $kepatuhan['selisih_hari'],
                'keterangan_kepatuhan'  => $kepatuhan['keterangan'],
                'early_warning'         => $kepatuhan['early_warning'] ?? '-',
            ]);

            if (count($pasienAktif) >= self::JUMLAH_PASIEN_TIDAK_PATUH) {
                break;
            }
        }

        // ── Data tren kepatuhan per bulan (12 bulan terakhir) ────────────
        //
        // Tren dibangun dari riwayat_pengambilan_obat — setiap siklus yang
        // jadwal_target-nya jatuh di bulan tersebut dihitung patuh/tidak patuh.
        // Filter FKTP diterapkan via join ke pasien_prb.
        //
        // Menggunakan jadwal_target (bukan tanggal_pengambilan) sebagai
        // penentu periode — konsisten dengan logika kepatuhan global.
        $trenQuery = RiwayatPengambilanObat::query()
            ->join('pasien_prb', 'riwayat_pengambilan_obat.pasien_prb_id', '=', 'pasien_prb.id')
            ->whereNotNull('riwayat_pengambilan_obat.jadwal_target')
            ->where('riwayat_pengambilan_obat.jadwal_target', '>=',
                Carbon::now()->subMonths(11)->startOfMonth()->toDateString()
            )
            ->where('riwayat_pengambilan_obat.jadwal_target', '<=',
                Carbon::now()->endOfMonth()->toDateString()
            )
            ->select(
                \DB::raw("strftime('%Y-%m', riwayat_pengambilan_obat.jadwal_target) as bulan"),
                \DB::raw("SUM(CASE WHEN riwayat_pengambilan_obat.status_kepatuhan = 'Patuh' THEN 1 ELSE 0 END) as patuh"),
                \DB::raw("SUM(CASE WHEN riwayat_pengambilan_obat.status_kepatuhan = 'Tidak Patuh' THEN 1 ELSE 0 END) as tidak_patuh")
            )
            ->groupBy('bulan')
            ->orderBy('bulan', 'asc');

        if ($filterFktp) {
            $trenQuery->where('pasien_prb.fktp_asal', $filterFktp);
        }

        // Bangun 12 slot bulan — isi 0 jika tidak ada data agar grafik mulus
        $trenRaw = $trenQuery->get()->keyBy('bulan');

        $trenKepatuhan = [];
        for ($i = 11; $i >= 0; $i--) {
            $bulan      = Carbon::now()->subMonths($i)->format('Y-m');
            $label      = Carbon::now()->subMonths($i)->translatedFormat('M Y');
            $row        = $trenRaw->get($bulan);
            $patuh      = $row ? (int) $row->patuh      : 0;
            $tidakPatuh = $row ? (int) $row->tidak_patuh : 0;
            $total      = $patuh + $tidakPatuh;

            $trenKepatuhan[] = [
                'bulan'        => $label,
                'patuh'        => $patuh,
                'tidak_patuh'  => $tidakPatuh,
                'persentase'   => $total > 0 ? round(($patuh / $total) * 100, 1) : 0,
            ];
        }

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
            'pasienAktif'    => $pasienAktif,
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
