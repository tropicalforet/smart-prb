<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReminderController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    /**
     * Halaman Reminder WhatsApp PRB.
     *
     * Menampilkan daftar pasien yang perlu diingatkan berdasarkan:
     * 1. Filter periode (dari jadwal_target / tanggal_jadwal_berikutnya)
     * 2. Kategori Early Warning (menggunakan hasil EWS yang sudah ada)
     *
     * TIDAK mengirim WhatsApp — hanya menyiapkan data untuk petugas.
     */
    public function index(Request $request)
    {
        $filterPeriode  = $request->input('filter_periode', '');
        $filterKategori = $request->input('filter_kategori', '');
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', Carbon::today()->toDateString());

        // ── Kumpulkan periode dinamis (sama dengan PasienPrbController) ──
        // Sumber: jadwal_target riwayat + tanggal_jadwal_berikutnya master
        $periodesJadwalTarget = RiwayatPengambilanObat::select('jadwal_target')
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
            ->map(fn ($p) => [
                'value' => $p,
                'label' => Carbon::parse($p . '-01')->translatedFormat('F Y'),
            ])->values()->toArray();

        // ── Kategori EWS yang tersedia untuk reminder ──────────────────
        // Hanya EWS yang relevan untuk notifikasi — menggunakan string
        // persis yang dikembalikan oleh tentukanStatusKepatuhan().
        $kategoriOptions = [
            ['value' => 'Jadwal mendekat',                  'label' => '🟡 Jadwal Mendekat (H-3 s/d H-1)'],
            ['value' => 'Jatuh tempo',                      'label' => '🟠 Jatuh Tempo (Hari H)'],
            ['value' => 'Belum mengambil, masih toleransi', 'label' => '🟠 Belum Mengambil, Masih Toleransi (H+1 s/d H+3)'],
            ['value' => 'Tidak patuh/Overdue',              'label' => '🔴 Tidak Patuh / Overdue (H+4 dst)'],
        ];

        // ── Ambil pasien jika periode & kategori sudah dipilih ─────────
        $pasienReminder = collect();

        if ($filterPeriode && $filterKategori) {
            // Query pasien yang memiliki siklus pada periode tersebut
            $query = PasienPrb::with('riwayatPengambilanObat')
                ->where('status', 'Aktif')
                ->where(function ($q) use ($filterPeriode) {
                    $q->whereHas('riwayatPengambilanObat', fn ($qr) =>
                        $qr->where('jadwal_target', 'like', $filterPeriode . '%')
                    )->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
                })
                ->orderBy('tanggal_jadwal_berikutnya', 'asc');

            $pasienReminder = $query->get()
                ->map(function ($pasien) use ($tanggalEvaluasi, $filterPeriode, $filterKategori) {
                    // Gunakan evaluasi berbasis periode untuk konsistensi dengan halaman Data Pasien
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode(
                        $pasien,
                        $filterPeriode,
                        $tanggalEvaluasi
                    );

                    $ews = $kepatuhan['early_warning'] ?? '-';

                    // Filter berdasarkan kategori EWS yang dipilih petugas
                    if ($ews !== $filterKategori) {
                        return null;
                    }

                    return [
                        'id'               => $pasien->id,
                        'kode_pasien'      => $pasien->kode_pasien,
                        'nama_pasien'      => $pasien->nama_pasien,
                        'no_whatsapp'      => $pasien->no_whatsapp,
                        'fktp_asal'        => $pasien->fktp_asal,
                        'jadwal'           => $kepatuhan['jadwal_target_aktif']
                                                ?? $pasien->tanggal_jadwal_berikutnya?->toDateString(),
                        'early_warning'    => $ews,
                        'status_kepatuhan' => $kepatuhan['status'],
                        'kedatangan'       => $kepatuhan['tanggal_realisasi_aktif'],
                    ];
                })
                ->filter() // hapus null
                ->values();
        }

        return Inertia::render('Reminder/Index', [
            'pasienReminder'  => $pasienReminder,
            'periodeOptions'  => $periodeOptions,
            'kategoriOptions' => $kategoriOptions,
            'filters' => [
                'filter_periode'  => $filterPeriode,
                'filter_kategori' => $filterKategori,
                'tanggal_evaluasi'=> $tanggalEvaluasi,
            ],
        ]);
    }
}
