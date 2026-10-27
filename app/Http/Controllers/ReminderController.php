<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Models\RiwayatTindakLanjut;
use App\Services\AuditLogService;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReminderController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService,
        private AuditLogService  $auditLogService
    ) {}

    /**
     * =========================================================
     * HALAMAN REMINDER WHATSAPP
     * =========================================================
     *
     * Menampilkan daftar pasien yang perlu diingatkan berdasarkan:
     * - Filter periode (dari jadwal_target / tanggal_jadwal_berikutnya)
     * - Filter FKTP
     * - Kategori Early Warning (string persis dari tentukanStatusKepatuhanPeriode)
     *
     * TIDAK mengirim WhatsApp — sistem hanya menyiapkan data.
     * Pengiriman dilakukan manual oleh petugas melalui "blast" simulasi.
     */
    public function index(Request $request)
    {
        $filterPeriode   = $request->input('filter_periode', '');
        $filterKategori  = $request->input('filter_kategori', '');
        $filterFktp      = $request->input('filter_fktp', '');
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', Carbon::today()->toDateString());

        // ── Periode dinamis ───────────────────────────────────────────────
        $periodeOptions = $this->kepatuhanService->daftarPeriode();

        // ── FKTP dinamis (hanya dari pasien Aktif) ────────────────────────
        $fktpOptions = PasienPrb::select('fktp_asal')
            ->where('status', 'Aktif')
            ->whereNotNull('fktp_asal')
            ->distinct()
            ->orderBy('fktp_asal')
            ->pluck('fktp_asal')
            ->toArray();

        // ── Kategori EWS ──────────────────────────────────────────────────
        $kategoriOptions = [
            ['value' => 'Jadwal mendekat',                  'label' => '🟡 Jadwal Mendekat (H-3 s/d H-1)'],
            ['value' => 'Jatuh tempo',                      'label' => '🟠 Jatuh Tempo (Hari H)'],
            ['value' => 'Belum mengambil, masih toleransi', 'label' => '🟠 Belum Mengambil, Masih Toleransi (H+1 s/d H+3)'],
            ['value' => 'Tidak patuh/Overdue',              'label' => '🔴 Tidak Patuh / Overdue (H+4 dst)'],
        ];

        // ── Ambil pasien jika periode & kategori sudah dipilih ────────────
        $pasienReminder = collect();

        if ($filterPeriode && $filterKategori) {
            $query = PasienPrb::with([
                    'riwayatPengambilanObat' => fn ($q) =>
                        $q->orderBy('tanggal_pengambilan', 'desc'),
                    'riwayatTindakLanjut' => fn ($q) =>
                        $q->orderBy('tanggal_jam', 'desc')->limit(5),
                ])
                ->where('status', 'Aktif')
                // Filter hanya pasien dengan nomor WhatsApp valid (min 10 digit)
                ->whereNotNull('no_whatsapp')
                ->where('no_whatsapp', '!=', '')
                ->whereRaw('LENGTH(no_whatsapp) >= 10')
                ->where(function ($q) use ($filterPeriode) {
                    $q->whereHas('riwayatPengambilanObat', fn ($qr) =>
                        $qr->where('jadwal_target', 'like', $filterPeriode . '%')
                    )->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
                });

            // Filter FKTP (opsional)
            if ($filterFktp) {
                $query->where('fktp_asal', $filterFktp);
            }

            $query->orderBy('tanggal_jadwal_berikutnya', 'asc');

            $pasienReminder = $query->get()
                ->map(function ($pasien) use ($tanggalEvaluasi, $filterPeriode, $filterKategori) {
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode(
                        $pasien,
                        $filterPeriode,
                        $tanggalEvaluasi
                    );

                    $ews = $kepatuhan['early_warning'] ?? '-';

                    if ($ews !== $filterKategori) {
                        return null;
                    }

                    $tindakLanjutTerbaru = $pasien->riwayatTindakLanjut
                        ->take(3)
                        ->map(fn ($tl) => [
                            'id'          => $tl->id,
                            'tanggal_jam' => $tl->tanggal_jam->format('d/m/Y H:i'),
                            'petugas'     => $tl->petugas,
                            'tindakan'    => $tl->tindakan,
                            'catatan'     => $tl->catatan,
                        ])
                        ->values()
                        ->toArray();

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
                        'tindak_lanjut'    => $tindakLanjutTerbaru,
                    ];
                })
                ->filter()
                ->values();
        }

        return Inertia::render('Reminder/Index', [
            'pasienReminder'  => $pasienReminder,
            'periodeOptions'  => $periodeOptions,
            'fktpOptions'     => $fktpOptions,
            'kategoriOptions' => $kategoriOptions,
            'tindakanOptions' => RiwayatTindakLanjut::TINDAKAN_OPTIONS,
            'filters' => [
                'filter_periode'   => $filterPeriode,
                'filter_kategori'  => $filterKategori,
                'filter_fktp'      => $filterFktp,
                'tanggal_evaluasi' => $tanggalEvaluasi,
            ],
        ]);
    }

    /**
     * =========================================================
     * BLAST SIMULASI REMINDER
     * =========================================================
     *
     * Alur:
     * 1. Terima daftar pasien_id yang dipilih petugas.
     * 2. Validasi nomor WhatsApp per pasien (format + ketersediaan).
     * 3. Untuk setiap pasien dengan nomor valid:
     *    - Simpan record RiwayatTindakLanjut dengan tindakan "Reminder WhatsApp dibuka".
     *      Sistem hanya membuka wa.me; tidak bisa memastikan pesan benar-benar
     *      dikirim petugas, sehingga label sengaja TIDAK memakai kata "terkirim".
     * 4. Kembalikan JSON ringkasan: berhasil / gagal / invalid.
     *
     * Status "Berhasil" = nomor valid & record berhasil disimpan.
     * Status "Gagal"    = record gagal disimpan (exception DB).
     * Status "Invalid"  = nomor tidak tersedia atau format salah.
     *
     * TIDAK mengubah status kepatuhan. Tidak ada API WhatsApp.
     * Pengiriman pesan tetap dilakukan manual oleh petugas.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function blast(Request $request)
    {
        $validated = $request->validate([
            'pasien_ids'     => 'required|array|min:1|max:200',
            'pasien_ids.*'   => 'required|string',
            'petugas'        => 'nullable|string|max:255',
            'filter_periode' => 'nullable|string',
            'filter_kategori'=> 'nullable|string',
        ]);

        // Resolusi nama petugas
        if (auth()->check() && !empty(auth()->user()->name)) {
            $namaPetugas = auth()->user()->name;
        } elseif (!empty($validated['petugas'])) {
            $namaPetugas = trim($validated['petugas']);
        } else {
            $namaPetugas = 'Petugas Apotek';
        }

        $filterPeriode  = $validated['filter_periode']  ?? '';
        $filterKategori = $validated['filter_kategori'] ?? '';

        // Ambil data pasien yang diminta (hanya yang Aktif, sekuritas ganda)
        $pasienList = PasienPrb::whereIn('id', $validated['pasien_ids'])
            ->where('status', 'Aktif')
            ->get()
            ->keyBy('id');

        $berhasil = 0;
        $gagal    = 0;
        $invalid  = 0;

        // Detail per pasien — dikembalikan ke frontend untuk tampil di ringkasan
        $detail = [];

        foreach ($validated['pasien_ids'] as $pasienId) {
            $pasien = $pasienList[$pasienId] ?? null;

            // Pasien tidak ditemukan atau tidak aktif → skip
            if (!$pasien) {
                $detail[] = [
                    'id'          => $pasienId,
                    'nama_pasien' => '-',
                    'no_whatsapp' => '-',
                    'status'      => 'invalid',
                    'keterangan'  => 'Pasien tidak ditemukan atau tidak aktif',
                ];
                $invalid++;
                continue;
            }

            // Validasi nomor WhatsApp
            $noWa   = $pasien->no_whatsapp ?? '';
            $noWaBersih = preg_replace('/[^0-9]/', '', $noWa);

            $noWaValid = !empty($noWaBersih)
                && strlen($noWaBersih) >= 9
                && strlen($noWaBersih) <= 15;

            if (!$noWaValid) {
                $detail[] = [
                    'id'          => $pasien->id,
                    'nama_pasien' => $pasien->nama_pasien,
                    'no_whatsapp' => $noWa ?: '-',
                    'status'      => 'invalid',
                    'keterangan'  => empty($noWaBersih)
                        ? 'Nomor WhatsApp tidak tersedia'
                        : 'Format nomor tidak valid',
                ];
                $invalid++;
                continue;
            }

            // Simpan catatan reminder ke riwayat tindak lanjut
            $catatan = 'Reminder pengambilan obat';
            if ($filterPeriode) {
                $catatan .= ' periode ' . Carbon::parse($filterPeriode . '-01')->translatedFormat('F Y');
            }
            if ($filterKategori) {
                $catatan .= ' · ' . $filterKategori;
            }

            try {
                RiwayatTindakLanjut::create([
                    'pasien_prb_id'             => $pasien->id,
                    'tanggal_jam'               => now(),
                    'petugas'                   => $namaPetugas,
                    'tindakan'                  => 'Reminder WhatsApp dibuka',
                    'catatan'                   => $catatan,
                    'update_realisasi_otomatis' => false,
                ]);

                $detail[] = [
                    'id'          => $pasien->id,
                    'nama_pasien' => $pasien->nama_pasien,
                    'no_whatsapp' => $noWa,
                    'status'      => 'berhasil',
                    'keterangan'  => 'WhatsApp dibuka, kirim pesan secara manual',
                ];
                $berhasil++;

            } catch (\Exception $e) {
                $detail[] = [
                    'id'          => $pasien->id,
                    'nama_pasien' => $pasien->nama_pasien,
                    'no_whatsapp' => $noWa,
                    'status'      => 'gagal',
                    'keterangan'  => 'Gagal menyimpan: ' . $e->getMessage(),
                ];
                $gagal++;
            }
        }

        if ($berhasil > 0) {
            $this->auditLogService->bukaReminder($berhasil, trim($filterPeriode . ' ' . $filterKategori));
        }

        return response()->json([
            'ringkasan' => [
                'dipilih' => count($validated['pasien_ids']),
                'berhasil'=> $berhasil,
                'gagal'   => $gagal,
                'invalid' => $invalid,
            ],
            'detail' => $detail,
        ]);
    }
}
