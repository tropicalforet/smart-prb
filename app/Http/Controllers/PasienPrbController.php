<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PasienPrbController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    /**
     * =========================================================
     * INDEX
     * =========================================================
     */
    public function index(Request $request)
    {
        $tanggalEvaluasi = $request->input(
            'tanggal_evaluasi',
            now()->toDateString()
        );

        $search = $request->input('search', '');
        $filterStatus = $request->input('filter_status', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $filterDiagnosis = $request->input('filter_diagnosis', '');
        $filterFktp = $request->input('filter_fktp', '');
        $filterPeriode = $request->input('filter_periode', '');

        // ---------------------------------------------------------------
        // KUMPULKAN PERIODE DINAMIS dari 2 sumber:
        //
        // 1. jadwal_target di riwayat — periode ditentukan dari SIKLUS
        //    yang menjadi target, bukan dari kapan pasien mengambil obat.
        //    Contoh: pengambilan 03/03 untuk jadwal 02/04 → siklus April.
        //    Sumber ini yang paling akurat untuk data historis.
        //
        // 2. tanggal_jadwal_berikutnya di master (pasien Aktif) — untuk
        //    siklus yang sedang berjalan dan belum ada riwayatnya.
        //
        // TIDAK menggunakan tanggal_pengambilan dari riwayat sebagai
        // sumber periode, karena realisasi boleh jatuh di bulan lain
        // dan akan membuat periode "Maret" muncul untuk siklus April.
        // ---------------------------------------------------------------
        $periodesJadwalTarget = \App\Models\RiwayatPengambilanObat::select('jadwal_target')
            ->whereNotNull('jadwal_target')
            ->pluck('jadwal_target')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->format('Y-m'));

        $periodesMaster = PasienPrb::select('tanggal_jadwal_berikutnya')
            ->whereNotNull('tanggal_jadwal_berikutnya')
            ->where('status', 'Aktif')
            ->pluck('tanggal_jadwal_berikutnya')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->format('Y-m'));

        $periodes = $periodesJadwalTarget
            ->merge($periodesMaster)
            ->filter()
            ->unique()
            ->sortDesc()
            ->map(function ($period) {
                return [
                    'value' => $period,
                    'label' => \Carbon\Carbon::parse($period . '-01')->translatedFormat('F Y'),
                ];
            })->values()->toArray();

        $query = PasienPrb::with([
            'riwayatPengambilanObat' => fn($q) =>
                $q->orderBy('tanggal_pengambilan', 'desc'),
        ])
            ->orderBy('kode_pasien', 'asc');

        /*
         * Pencarian:
         * - Nama pasien
         * - Kode pasien
         * - Nomor BPJS
         */
        if ($search) {
            $query->where(function ($q) use ($search) {
                $searchLike = '%' . strtolower($search) . '%';

                $q->whereRaw(
                    'LOWER(nama_pasien) LIKE ?',
                    [$searchLike]
                )
                    ->orWhereRaw(
                        'LOWER(kode_pasien) LIKE ?',
                        [$searchLike]
                    )
                    ->orWhereRaw(
                        'LOWER(no_kartu_bpjs) LIKE ?',
                        [$searchLike]
                    );
            });
        }

        /*
         * Filter status pasien
         */
        if ($filterStatus) {
            $query->where(
                'status',
                $filterStatus
            );
        }

        /*
         * Filter diagnosis
         */
        if ($filterDiagnosis) {
            $query->whereJsonContains(
                'diagnosis_prb',
                $filterDiagnosis
            );
        }

        /*
         * Filter fktp
         */
        if ($filterFktp) {
            $query->where(
                'fktp_asal',
                $filterFktp
            );
        }

        /*
         * Filter periode
         * Hanya menyaring data, tidak mematikan logic Early Warning
         */
        if ($filterPeriode) {
            $query->where(function ($q) use ($filterPeriode) {
                $q->whereHas('riwayatPengambilanObat', function ($qRiwayat) use ($filterPeriode) {
                    $qRiwayat->where('jadwal_target', 'like', $filterPeriode . '%');
                })->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
            });
        }

        /*
         * Hitung status kepatuhan berdasarkan tanggal evaluasi.
         *
         * PENTING — Kolom kontekstual per mode:
         *
         * Mode "Semua Periode" (tanpa filterPeriode):
         *   - status_kepatuhan   → kondisi siklus aktif terkini
         *   - early_warning      → kondisi siklus aktif terkini
         *   - jadwal_target_aktif → tanggal_jadwal_berikutnya master
         *   - tanggal_realisasi_aktif → realisasi siklus aktif (dari riwayat yg jadwal_target=jadwalAktif)
         *   - tanggal_pengambilan_terakhir → dari master (terbaru global, ditampilkan di kolom "Terakhir Ambil Obat")
         *
         * Mode Periode Tertentu (filterPeriode aktif):
         *   - status_kepatuhan   → kondisi siklus periode tersebut
         *   - early_warning      → kondisi siklus periode tersebut (BUKAN siklus terbaru)
         *   - jadwal_target_aktif → jadwal_target dari riwayat siklus periode tersebut
         *   - tanggal_realisasi_aktif → tanggal_pengambilan dari riwayat siklus periode tersebut
         *   - tanggal_pengambilan_terakhir → JUGA dari siklus periode tersebut
         *     (= sama dengan tanggal_realisasi_aktif, karena "terakhir ambil" harus relevan
         *      dengan siklus yang sedang ditampilkan, bukan tanggal global terbaru)
         */
        $pasienAll = $query
            ->get()
            ->map(function ($pasien) use (
                $tanggalEvaluasi, $filterPeriode
            ) {
                if ($filterPeriode) {
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $tanggalEvaluasi);
                } else {
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                }

                $statusKepatuhan = $kepatuhan['status'];
                $selisihHari = $kepatuhan['selisih_hari'];
                $keterangan = $kepatuhan['keterangan'];
                $earlyWarning = $kepatuhan['early_warning'] ?? '-';

                // jadwal_target_aktif: dari service.
                // Mode Semua Periode → jadwal aktif master (siklus berikutnya).
                // Mode Periode Tertentu → jadwal_target riwayat siklus periode tsb.
                //
                // JANGAN fallback ke tanggal_jadwal_berikutnya master saat mode periode aktif,
                // karena itu akan menampilkan jadwal siklus terbaru (mis. November 2026)
                // saat user sedang melihat periode historis (mis. Februari 2024).
                // Jika service tidak menemukan siklus di periode ini (Tidak Ada Transaksi),
                // kolom jadwal harus null — bukan jadwal master.
                $jadwalTarget = $kepatuhan['jadwal_target_aktif']
                    ?? ($filterPeriode
                        ? null  // Mode periode: tidak ada fallback ke master
                        : $pasien->tanggal_jadwal_berikutnya?->toDateString()  // Mode semua: fallback boleh
                    );

                // tanggal_realisasi_aktif: dari service (Kedatangan Pasien)
                // Mode Semua Periode → tanggal_pengambilan riwayat terbaru
                // Mode Periode Tertentu → tanggal_pengambilan riwayat siklus periode
                $tglRealisasi = $kepatuhan['tanggal_realisasi_aktif'] ?? null;

                // riwayat_id siklus aktif — digunakan frontend untuk edit (PUT) vs create (POST)
                $riwayatIdAktif = $kepatuhan['riwayat_id_aktif'] ?? null;

                // tanggal_pengambilan_terakhir yang ditampilkan di kolom "Terakhir Ambil Obat":
                // SELALU dari riwayat terakhir global pasien — tidak dipengaruhi filter periode.
                //
                // Pemisahan yang benar:
                //   "Terakhir Ambil Obat" → histori terbaru keseluruhan (global)
                //   "Kedatangan Pasien"   → realisasi siklus aktif/periode ($tglRealisasi)
                //
                // Filter periode hanya menentukan SIKLUS MANA yang dievaluasi untuk
                // EWS, status, dan kedatangan — bukan membatasi histori yang ditampilkan.
                $tglTerakhirDisplay = $pasien->tanggal_pengambilan_terakhir?->toDateString();

                return array_merge(
                    $pasien->toArray(),
                    [
                        'status_kepatuhan' =>
                            $statusKepatuhan,

                        'selisih_hari_evaluasi' =>
                            $selisihHari,

                        'keterangan_kepatuhan' =>
                            $keterangan,

                        'early_warning' =>
                            $earlyWarning,

                        'jadwal_target_aktif' =>
                            $jadwalTarget,

                        'tanggal_realisasi_aktif' =>
                            $tglRealisasi,

                        'riwayat_id_aktif' =>
                            $riwayatIdAktif,

                        // Override tanggal_pengambilan_terakhir agar kolom
                        // "Terakhir Ambil Obat" konsisten dengan mode/periode
                        'tanggal_pengambilan_terakhir' =>
                            $tglTerakhirDisplay,
                    ]
                );
            });

        /*
         * Filter status kepatuhan
         * dilakukan setelah status dihitung.
         */
        if ($filterKepatuhan) {
            $pasienAll = $pasienAll
                ->filter(function ($p) use (
                    $filterKepatuhan
                ) {
                    return
                        $p['status_kepatuhan']
                        === $filterKepatuhan;
                })
                ->values();
        }

        return Inertia::render(
            'Pasien/Index',
            [
                'pasienList' =>
                    $pasienAll,

                'tanggalEvaluasi' =>
                    $tanggalEvaluasi,

                'filters' => [
                    'search' =>
                        $search,
                    
                    'filter_periode' =>
                        $filterPeriode,

                    'filter_status' =>
                        $filterStatus,

                    'filter_kepatuhan' =>
                        $filterKepatuhan,

                    'filter_diagnosis' =>
                        $filterDiagnosis,

                    'filter_fktp' =>
                        $filterFktp,
                ],

                'diagnosisOptions' =>
                    PasienPrb::DIAGNOSIS_OPTIONS,

                'fktpOptions' =>
                    PasienPrb::select('fktp_asal')
                        ->distinct()
                        ->pluck('fktp_asal')
                        ->filter()
                        ->values()
                        ->toArray(),

                'alasanPasifOptions' =>
                    PasienPrb::ALASAN_PASIF_OPTIONS,

                'periodeOptions' => $periodes,
            ]
        );
    }

    /**
     * =========================================================
     * STORE
     * =========================================================
     *
     * Tambah pasien baru.
     *
     * LOGIKA:
     *
     * Belum pernah mengambil obat
     * → PRB Baru
     * → Jatah 23 hari
     * → Jadwal = Mulai PRB + 23 hari
     *
     * Sudah pernah mengambil obat
     * → PRB Lama
     * → Jatah 30 hari
     * → Jadwal = Pengambilan Terakhir + 30 hari
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_pasien' => [
                'required', 'string', 'max:255', 'regex:/^[\pL\pN\pP\pZ]+$/u'
            ],

            'no_kartu_bpjs' => [
                'required',
                'digits:' . KepatuhanService::BPJS_DIGIT,
                Rule::unique('pasien_prb', 'no_kartu_bpjs'),
            ],

            'no_whatsapp' =>
                'nullable|digits_between:10,13',

            'fktp_asal' => [
                'required', 'string', 'max:255', 'regex:/^[\pL\pN\pP\pZ]+$/u'
            ],

            'diagnosis_prb' => [
                'required',
                'array',
                'min:1'
            ],

            // Diagnosis PRB bersifat TERBUKA — tidak dibatasi daftar tetap.
            // Apotek/FKTP dapat menggunakan nama diagnosis sesuai data aktual
            // mereka. DIAGNOSIS_OPTIONS di model adalah referensi untuk UI,
            // bukan validator ketat.
            'diagnosis_prb.*' => [
                'required',
                'string',
                'max:100',
            ],

            'tanggal_mulai_prb' =>
                'required|date',

            'tanggal_pengambilan_terakhir' =>
                'nullable|date|before_or_equal:today',

            'status' =>
                'required|in:Aktif,Pasif',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',

            'sumber_data' =>
                'nullable|string',
        ], [
            'no_kartu_bpjs.unique' => 'Pasien dengan No. Kartu BPJS tersebut sudah terdaftar.',
        ]);

        /*
         * Generate kode pasien otomatis.
         */
        $validated['kode_pasien'] =
            PasienPrb::generateKodePasien();

        /*
         * Tentukan PRB Baru/Lama
         * dan jadwal berikutnya.
         */
        $this->setJenisDanJadwal(
            $validated
        );

        /*
         * Belum ada realisasi
         * saat pasien dibuat (kecuali jika ada tanggal_pengambilan_terakhir).
         */
        $validated['tanggal_realisasi'] = null;

        $pasien = PasienPrb::create(
            $validated
        );

        /*
         * Jika ada tanggal pengambilan terakhir saat input manual,
         * catat sebagai transaksi awal di riwayat agar muncul di filter periode.
         *
         * tanggal_berobat = NULL karena form tambah pasien tidak memiliki
         * field "Tanggal Berobat" — jangan mengisi dengan tanggal_pengambilan
         * agar tidak mengarang data yang tidak diberikan pengguna.
         */
        if (!empty($validated['tanggal_pengambilan_terakhir'])) {
            $tglAmbilDb = Carbon::parse($validated['tanggal_pengambilan_terakhir'])->toDateString();
            \App\Models\RiwayatPengambilanObat::create([
                'pasien_prb_id'       => $pasien->id,
                'no_faktur'           => 'MANUAL-' . date('Ymd') . '-' . $pasien->id,
                'tanggal_berobat'     => null, // tidak tersedia dari form — jangan dikarang
                'tanggal_pengambilan' => $tglAmbilDb,
                'jadwal_target'       => $tglAmbilDb, // data awal, anggap on-time
                'selisih_hari'        => 0,
                'status_kepatuhan'    => 'Patuh',
            ]);
        }

        return redirect()
            ->route('pasien.index')
            ->with(
                'success',
                'Pasien berhasil ditambahkan.'
            );
    }

    /**
     * =========================================================
     * SHOW
     * =========================================================
     *
     * Endpoint JSON untuk PatientDetailModal.
     *
     * Mendukung parameter:
     *   tanggal_evaluasi  — tanggal referensi evaluasi kepatuhan
     *   filter_periode    — jika ada, evaluasi dalam konteks periode ini
     *                       sehingga modal menampilkan kondisi siklus
     *                       periode yang sedang dilihat user, bukan
     *                       kondisi siklus terbaru pasien.
     */
    public function show(
        Request $request,
        PasienPrb $pasien
    ) {
        $tanggalEvaluasi = $request->input(
            'tanggal_evaluasi',
            now()->toDateString()
        );

        $filterPeriode = $request->input('filter_periode', '');

        $pasien->load([
            'riwayatPengambilanObat' =>
                fn ($q) =>
                $q->orderBy(
                    'tanggal_pengambilan',
                    'desc'
                ),

            'riwayatTindakLanjut' =>
                fn ($q) =>
                $q->orderBy(
                    'tanggal_jam',
                    'desc'
                ),
        ]);

        // Evaluasi kepatuhan sesuai konteks:
        // - Ada filter_periode → gunakan mode periode (kontekstual)
        // - Tidak ada → gunakan mode siklus aktif (kondisi terkini)
        if ($filterPeriode) {
            $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode(
                $pasien,
                $filterPeriode,
                $tanggalEvaluasi
            );
        } else {
            $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan(
                $pasien,
                $tanggalEvaluasi
            );
        }

        $data = array_merge(
            $pasien->toArray(),
            [
                'status_kepatuhan' =>
                    $kepatuhan['status'],

                'selisih_hari_evaluasi' =>
                    $kepatuhan['selisih_hari'],

                'keterangan_kepatuhan' =>
                    $kepatuhan['keterangan'],

                'early_warning' =>
                    $kepatuhan['early_warning'] ?? '-',

                'jadwal_target_aktif' =>
                    $kepatuhan['jadwal_target_aktif'] ?? null,

                'tanggal_realisasi_aktif' =>
                    $kepatuhan['tanggal_realisasi_aktif'] ?? null,

                'riwayat_id_aktif' =>
                    $kepatuhan['riwayat_id_aktif'] ?? null,
            ]
        );

        return response()->json(
            $data
        );
    }

    /**
     * =========================================================
     * UPDATE
     * =========================================================
     *
     * Jika tanggal pengambilan terakhir diubah,
     * jadwal juga harus dihitung ulang.
     *
     * Contoh:
     *
     * Terakhir ambil:
     * 15/09/2026
     *
     * → PRB Lama
     * → 30 hari
     * → Jadwal 15/10/2026
     */
    public function update(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'nama_pasien' => [
                'required', 'string', 'max:255', 'regex:/^[\pL\pN\pP\pZ]+$/u'
            ],

            'no_kartu_bpjs' => [
                'required',
                'digits:' . KepatuhanService::BPJS_DIGIT,
                \Illuminate\Validation\Rule::unique('pasien_prb', 'no_kartu_bpjs')->ignore($pasien->id),
            ],

            'no_whatsapp' =>
                'nullable|digits_between:10,13',

            'fktp_asal' => [
                'required', 'string', 'max:255', 'regex:/^[\pL\pN\pP\pZ]+$/u'
            ],

            'diagnosis_prb' => [
                'required',
                'array',
                'min:1'
            ],

            // Diagnosis PRB bersifat TERBUKA — tidak dibatasi daftar tetap.
            'diagnosis_prb.*' => [
                'required',
                'string',
                'max:100',
            ],

            'tanggal_mulai_prb' =>
                'required|date',

            'tanggal_pengambilan_terakhir' =>
                'nullable|date|before_or_equal:today',

            'status' =>
                'required|in:Aktif,Pasif',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',
        ]);

        /*
         * Cek tanggal pengambilan terbaru.
         *
         * Jika field kosong tetapi pasien sudah memiliki
         * riwayat pengambilan, gunakan riwayat terakhir.
         *
         * Tujuannya agar pasien lama tidak berubah
         * menjadi pasien baru secara tidak sengaja.
         */
        $tanggalTerakhir =
            $validated[
                'tanggal_pengambilan_terakhir'
            ] ?? null;

        if (empty($tanggalTerakhir)) {
            $riwayatTerakhir =
                $pasien
                    ->riwayatPengambilanObat()
                    ->latest(
                        'tanggal_pengambilan'
                    )
                    ->first();

            if ($riwayatTerakhir) {
                $tanggalTerakhir =
                    $riwayatTerakhir
                        ->tanggal_pengambilan
                        ?->toDateString();

                $validated[
                    'tanggal_pengambilan_terakhir'
                ] = $tanggalTerakhir;
            }
        }

        /*
         * Hitung ulang jenis PRB,
         * jatah obat, dan jadwal.
         */
        $this->setJenisDanJadwal(
            $validated
        );

        $pasien->update(
            $validated
        );

        return redirect()
            ->back()
            ->with(
                'success',
                'Data pasien berhasil diperbarui.'
            );
    }

    /**
     * =========================================================
     * DESTROY
     * =========================================================
     */
    public function destroy(
        PasienPrb $pasien
    ) {
        $pasien->delete();

        return redirect()
            ->route('pasien.index')
            ->with(
                'success',
                'Pasien berhasil dihapus.'
            );
    }

    /**
     * =========================================================
     * DESTROY BULK
     * =========================================================
     *
     * Hapus beberapa pasien sekaligus.
     *
     * Parameter:
     * - ids: array UUID pasien yang akan dihapus
     * - all: boolean, jika true hapus semua pasien
     */
    public function destroyBulk(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'uuid',
            'all' => 'nullable|boolean',
        ]);

        if (!empty($validated['all'])) {
            $count = PasienPrb::count();
            PasienPrb::query()->delete();
        } else {
            $ids = $validated['ids'] ?? [];
            $count = count($ids);
            PasienPrb::whereIn('id', $ids)->delete();
        }

        return redirect()
            ->route('pasien.index')
            ->with(
                'success',
                "$count pasien berhasil dihapus."
            );
    }

    /**
     * =========================================================
     * UPDATE REALISASI — KOREKSI TANGGAL KEDATANGAN
     * =========================================================
     *
     * Digunakan saat petugas mengoreksi tanggal realisasi yang sudah
     * tercatat di riwayat — berbeda dengan POST /realisasi yang membuat
     * siklus baru.
     *
     * Menerima:
     *   riwayat_id        — ID record riwayat_pengambilan_obat yang dikoreksi
     *   tanggal_realisasi — tanggal baru yang diinginkan
     *
     * Yang berubah:
     *   - riwayat.tanggal_pengambilan
     *   - riwayat.selisih_hari (dihitung ulang vs jadwal_target siklus)
     *   - riwayat.status_kepatuhan (dihitung ulang H-3/H+3)
     *   - pasien_prb.tanggal_pengambilan_terakhir (jika riwayat ini yang terbaru)
     *   - pasien_prb.tanggal_jadwal_berikutnya (jika riwayat ini yang terbaru)
     *
     * Yang TIDAK berubah:
     *   - riwayat.jadwal_target (tetap jadwal siklus asli)
     *   - riwayat.no_faktur
     *   - riwayat lain yang tidak terkait
     */
    public function updateRealisasiEdit(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'riwayat_id'        => 'required|uuid',
            'tanggal_realisasi' => 'required|date',
        ]);

        $hasil = $this->kepatuhanService->editRealisasi(
            $pasien,
            $validated['riwayat_id'],
            $validated['tanggal_realisasi']
        );

        if (isset($hasil['error'])) {
            return redirect()->back()->with('info', $hasil['error']);
        }

        if ($hasil['tidak_berubah'] ?? false) {
            return redirect()->back()->with('info', 'Tanggal tidak berubah.');
        }

        if ($hasil['duplikat'] ?? false) {
            return redirect()->back()->with('info', 'Sudah ada realisasi pada tanggal tersebut.');
        }

        return redirect()->back()->with('success', 'Tanggal kedatangan berhasil diperbarui.');
    }

    /**
     * =========================================================
     * UPDATE REALISASI (QuickRealisasi / Siklus Baru)
     * =========================================================
     *
     * Menerima tanggal realisasi dari form Quick Realisasi,
     * memanggil prosesRealisasi() yang bersifat atomik dan idempotent.
     *
     * Jika realisasi pada tanggal tersebut sudah pernah tercatat:
     *   → kembalikan pesan informatif, BUKAN 500.
     * Jika baru:
     *   → simpan histori + update master dalam satu transaksi.
     */
    public function updateRealisasi(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_realisasi' => 'required|date',
        ]);

        $hasil = $this->kepatuhanService
            ->prosesRealisasi(
                $pasien,
                $validated['tanggal_realisasi']
            );

        if ($hasil['duplikat']) {
            return redirect()
                ->back()
                ->with(
                    'info',
                    'Realisasi pada tanggal tersebut sudah tercatat sebelumnya.'
                );
        }

        return redirect()
            ->back()
            ->with(
                'success',
                'Realisasi pengambilan obat berhasil dicatat.'
            );
    }

    /**
     * =========================================================
     * UPDATE JADWAL
     * =========================================================
     *
     * Edit manual tanggal jadwal berikutnya.
     */
    public function updateJadwal(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_jadwal_berikutnya' => 'required|date',
        ]);

        $pasien->update([
            'tanggal_jadwal_berikutnya' => $validated['tanggal_jadwal_berikutnya']
        ]);

        return redirect()
            ->back()
            ->with(
                'success',
                'Jadwal pengambilan obat berhasil diperbarui.'
            );
    }

    /**
     * =========================================================
     * UPDATE TERAKHIR AMBIL OBAT
     * =========================================================
     *
     * Edit manual tanggal pengambilan terakhir.
     *
     * PENTING: mengubah tanggal_pengambilan_terakhir tanpa
     * menyesuaikan tanggal_jadwal_berikutnya akan membuat
     * data tidak sinkron (misalnya: terakhir=28/08 tapi
     * jadwal masih 27/09 yang lama, bukan 27/09 baru).
     *
     * Oleh karena itu, setiap perubahan terakhir_ambil
     * HARUS diikuti recalculate jadwal dan jenis PRB.
     */
    public function updateTerakhirAmbil(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_pengambilan_terakhir' => 'required|date|before_or_equal:today',
        ]);

        // Hitung ulang jenis PRB, jatah, dan jadwal agar selalu sinkron.
        // Aturan: jika ada tanggal_pengambilan_terakhir → PRB Lama, jadwal = terakhir+30.
        $tglTerakhir = $validated['tanggal_pengambilan_terakhir'];
        $updateData  = [
            'tanggal_pengambilan_terakhir' => $tglTerakhir,
            'jenis_prb'                    => 'Lama',
            'jatah_obat'                   => KepatuhanService::JATAH_PRB_LAMA,
            'tanggal_jadwal_berikutnya'    => Carbon::parse($tglTerakhir)->addDays(KepatuhanService::JATAH_PRB_LAMA)->toDateString(),
        ];

        $pasien->update($updateData);

        return redirect()
            ->back()
            ->with(
                'success',
                'Tanggal terakhir ambil obat berhasil diperbarui dan jadwal disinkronkan.'
            );
    }

    /**
     * =========================================================
     * UPDATE STATUS
     * =========================================================
     */
    public function updateStatus(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'status' =>
                'required|in:Aktif,Pasif',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',
        ]);

        /*
         * Jika kembali aktif,
         * alasan pasif dihapus.
         */
        if (
            $validated['status']
            === 'Aktif'
        ) {
            $validated['alasan_pasif'] = null;
        }

        $pasien->update(
            $validated
        );

        return redirect()
            ->back()
            ->with(
                'success',
                'Status pasien berhasil diperbarui.'
            );
    }

    /**
     * =========================================================
     * IMPORT EXCEL
     * =========================================================
     *
     * Kolom Excel yang didukung (sesuai template):
     *   No. Faktur / No. Transaksi Apotek  → no_faktur (Prioritas 1 duplikat check)
     *   Kode Pasien                         → kode_pasien
     *   Nama Pasien                         → nama_pasien
     *   No. Kartu BPJS                      → no_kartu_bpjs
     *   No. WhatsApp                        → no_whatsapp
     *   FKTP Asal                           → fktp_asal
     *   Diagnosis PRB                       → diagnosis_prb
     *   Tanggal Mulai PRB                   → tanggal_mulai_prb
     *   Tanggal Berobat                     → tanggal_berobat
     *   Tanggal Pengambilan                 → tanggal_pengambilan
     *
     * ALUR PER BARIS (semua di dalam DB::transaction per baris):
     *
     *   1. Normalisasi field dari input
     *   2. Validasi field wajib
     *      → jika tidak valid: simpan ke resep_import (invalid), lanjut baris berikutnya
     *   3. Cek duplikasi transaksi SEBELUM insert apapun:
     *      Prioritas 1 — no_faktur: cek di riwayat_pengambilan_obat
     *      Prioritas 2 — pasien_prb_id + tanggal_berobat (jika no_faktur kosong)
     *      → jika duplikat: simpan ke resep_import (duplikat), lanjut baris berikutnya
     *   4. Cari atau buat master pasien
     *   5. Tentukan jadwal_target dari jadwal aktif pasien SEBELUM dimajukan
     *   6. Insert riwayat_pengambilan_obat (transaksi baru)
     *   7. Update master pasien jika transaksi ini lebih baru dari terakhir
     *   8. Simpan ke resep_import (baru) dengan keterangan lengkap
     *
     * PRINSIP KUMULATIF:
     *   Import bulan baru menambahkan data — tidak menimpa histori lama.
     *   Master pasien hanya diupdate scheduling-nya jika ada transaksi lebih baru.
     *   Profil pasien (nama, fktp, diagnosis, whatsapp) TIDAK diupdate untuk pasien lama.
     *
     * SAFETY:
     *   - DB::transaction per baris → gagal satu tidak merusak baris lain
     *   - Cek duplikat sebelum INSERT → tidak mengandalkan UNIQUE constraint sebagai mekanisme utama
     *   - try/catch QueryException sebagai last-resort guard untuk race condition
     *   - Tidak ada staging insert sebelum validasi (menghindari penumpukan resep_import)
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'data'                               => 'required|array|min:1',
            'data.*.no_faktur'                   => 'nullable|string|max:100',
            'data.*.no_transaksi'                => 'nullable|string|max:100',
            'data.*.kode_pasien'                 => 'nullable|string|max:50',
            'data.*.nama_pasien'                 => 'nullable|string|max:255',
            'data.*.no_kartu_bpjs'               => 'nullable|string|max:50',
            'data.*.no_whatsapp'                 => 'nullable|string|max:20',
            'data.*.fktp_asal'                   => 'nullable|string|max:255',
            'data.*.diagnosis_prb'               => 'nullable',
            'data.*.tanggal_mulai_prb'           => 'nullable|string',
            'data.*.tanggal_berobat'             => 'nullable|string',
            'data.*.tanggal_pengambilan'         => 'nullable|string',
            'data.*.tanggal_pengambilan_terakhir'=> 'nullable|string',
        ]);

        $rows = $validated['data'];

        // Counter ringkasan untuk response
        $jumlahBaru       = 0;
        $jumlahDiperbarui = 0;
        $jumlahDuplikat   = 0;
        $jumlahInvalid    = 0;

        // Pelacak kunci transaksi dalam batch ini untuk mencegah
        // duplikasi antar baris dalam satu file yang sama.
        // Key: 'faktur_{noFaktur}' atau 'bpjs_tgl_{bpjs}_{tanggalBerobat}'
        $processedKeys = [];

        foreach ($rows as $row) {
            // ----------------------------------------------------------------
            // LANGKAH 1: NORMALISASI FIELD
            // ----------------------------------------------------------------

            // NO. FAKTUR / NO. TRANSAKSI APOTEK
            // ─────────────────────────────────────────────────────────────
            // Konteks penggunaan:
            //
            //   Data TESTING/DUMMY  : No. Invoice dummy (misal "CONTOH-INV-001")
            //                         digunakan selama development dan pengujian.
            //                         Nomor ini tidak mencerminkan transaksi nyata.
            //
            //   Data PENELITIAN/REAL: No. Invoice/Transaksi ASLI dari sistem apotek
            //                         (misal "INV-2026-0001") akan digunakan saat
            //                         data nyata dari Apotek Medika Antapani diberikan.
            //
            // ATURAN:
            //   - Sistem TIDAK men-generate nomor invoice — nilai disimpan apa adanya.
            //   - Sistem TIDAK memodifikasi nilai dari sumber (apotek).
            //   - REALISASI-YYYYMMDD-{uuid} HANYA digunakan oleh KepatuhanService
            //     untuk realisasi manual dari UI (bukan dari import Excel).
            //   - Nomor dummy dan nomor asli diperlakukan IDENTIK oleh logic ini.
            $noFaktur = !empty($row['no_faktur'])
                ? trim((string)$row['no_faktur'])
                : null;
            if (!$noFaktur && !empty($row['no_transaksi'])) {
                $noFaktur = trim((string)$row['no_transaksi']);
            }

            $noKartuBpjs = !empty($row['no_kartu_bpjs'])
                ? trim((string)$row['no_kartu_bpjs'])
                : null;
            $namaPasien  = !empty($row['nama_pasien'])
                ? trim((string)$row['nama_pasien'])
                : null;
            $fktpAsal    = !empty($row['fktp_asal'])
                ? trim((string)$row['fktp_asal'])
                : null;
            $kodePasien  = !empty($row['kode_pasien'])
                ? trim((string)$row['kode_pasien'])
                : null;

            $diagnosisPrb = [];
            if (!empty($row['diagnosis_prb']) && is_array($row['diagnosis_prb'])) {
                $diagnosisPrb = $row['diagnosis_prb'];
            } elseif (!empty($row['diagnosis_prb']) && is_string($row['diagnosis_prb'])) {
                $diagnosisPrb = array_map('trim', explode(',', $row['diagnosis_prb']));
            }

            $tglMulaiDb  = !empty($row['tanggal_mulai_prb'])
                ? Carbon::parse($row['tanggal_mulai_prb'])->toDateString()
                : null;
            $tglBerobatDb = !empty($row['tanggal_berobat'])
                ? Carbon::parse($row['tanggal_berobat'])->toDateString()
                : null;

            // tanggal_pengambilan: ambil dari kolom pengambilan, fallback ke pengambilan_terakhir
            $tglAmbilDb = null;
            if (!empty($row['tanggal_pengambilan'])) {
                $tglAmbilDb = Carbon::parse($row['tanggal_pengambilan'])->toDateString();
            } elseif (!empty($row['tanggal_pengambilan_terakhir'])) {
                $tglAmbilDb = Carbon::parse($row['tanggal_pengambilan_terakhir'])->toDateString();
            }

            // hasTransaction: baris ini membawa data transaksi (bukan sekadar profil)
            $hasTransaction = !empty($noFaktur) || !empty($tglBerobatDb) || !empty($tglAmbilDb);

            // Normalisasi:
            // - Jika tanggal_berobat ada tapi tanggal_pengambilan kosong → gunakan berobat sebagai pengambilan
            // - TIDAK sebaliknya: jangan isi tanggal_berobat dari tanggal_pengambilan
            //   karena keduanya bisa berbeda (berobat = hari kunjungan, pengambilan = hari ambil obat)
            if ($hasTransaction) {
                if (!$tglAmbilDb && $tglBerobatDb) $tglAmbilDb = $tglBerobatDb;
                // tanggal_berobat tetap NULL jika memang tidak ada di Excel — jangan dikarang
            }

            // ----------------------------------------------------------------
            // LANGKAH 2: VALIDASI FIELD WAJIB
            //
            // Jika tidak valid → simpan ke resep_import sebagai 'invalid'.
            // Tidak masuk ke riwayat maupun master pasien.
            // ----------------------------------------------------------------
            $invalidReasons = [];
            if (empty($namaPasien)) {
                $invalidReasons[] = 'Nama Pasien wajib diisi';
            }
            if (empty($noKartuBpjs) || strlen((string)$noKartuBpjs) !== KepatuhanService::BPJS_DIGIT) {
                $invalidReasons[] = 'No. Kartu BPJS harus ' . KepatuhanService::BPJS_DIGIT . ' digit';
            }
            if (empty($fktpAsal)) {
                $invalidReasons[] = 'FKTP Asal wajib diisi';
            }
            if (empty($diagnosisPrb)) {
                $invalidReasons[] = 'Diagnosis PRB wajib diisi';
            }
            if (empty($tglMulaiDb)) {
                $invalidReasons[] = 'Tanggal Mulai PRB wajib diisi';
            }

            if (!empty($invalidReasons)) {
                // Simpan ke staging sebagai invalid — tidak perlu transaksi DB
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'no_kartu_bpjs'      => $noKartuBpjs ?? '-',
                    'nama_pasien'        => $namaPasien  ?? '-',
                    'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                    'fktp_asal'          => $fktpAsal    ?? '-',
                    'diagnosis_prb'      => $diagnosisPrb,
                    'no_faktur'          => $noFaktur,
                    'tanggal_berobat'    => $tglBerobatDb,
                    'tanggal_pengambilan'=> $tglAmbilDb,
                    'tanggal_mulai_prb'  => $tglMulaiDb,
                    'status_import'      => 'invalid',
                    'keterangan_import'  => implode(', ', $invalidReasons),
                ]);
                $jumlahInvalid++;
                continue;
            }

            // ----------------------------------------------------------------
            // LANGKAH 3: CEK DUPLIKASI TRANSAKSI & UPDATE JIKA PERLU
            //
            // Prioritas 1 — no_faktur (jika tersedia):
            //   - Faktur ditemukan DAN data SAMA   → duplikat, skip
            //   - Faktur ditemukan DAN data BEDA   → UPDATE riwayat yang ada
            //   - Faktur belum ada                 → INSERT baru (lanjut ke Langkah 4)
            //
            // Prioritas 2 — BPJS + tanggal_berobat (jika no_faktur kosong):
            //   - Kombinasi ditemukan              → duplikat, skip
            //   - Kombinasi belum ada              → INSERT baru
            //
            // FIX batchKey: kunci hanya masuk $processedKeys jika transaksi
            // benar-benar diproses (tglAmbilDb tidak null). Ini mencegah baris
            // pertama yang tidak lengkap memblokir baris berikutnya yang lengkap.
            // ----------------------------------------------------------------
            $isDuplicate          = false;
            $isUpdateFaktur       = false;   // true → UPDATE riwayat yang ada
            $riwayatUntukUpdate   = null;    // record yang akan di-UPDATE
            $duplicateReason      = '';

            if ($hasTransaction) {
                // Tentukan kunci duplikasi
                if (!empty($noFaktur)) {
                    $batchKey = 'faktur_' . $noFaktur;
                } else {
                    $batchKey = 'bpjs_tgl_' . $noKartuBpjs . '_' . $tglBerobatDb;
                }

                // Lapis A: in-batch deduplication
                if (in_array($batchKey, $processedKeys, true)) {
                    $isDuplicate     = true;
                    $duplicateReason = "Duplikat dalam file yang sama (key: {$batchKey})";
                }

                // Lapis B: DB check (hanya jika belum terbukti duplikat dari batch)
                if (!$isDuplicate) {
                    if (!empty($noFaktur)) {
                        // Prioritas 1: cek no_faktur di riwayat
                        $riwayatLama = RiwayatPengambilanObat::where('no_faktur', $noFaktur)->first();

                        if ($riwayatLama) {
                            // Faktur sudah ada — cek apakah data berubah
                            $tglAmbilLama   = $riwayatLama->tanggal_pengambilan
                                ? Carbon::parse($riwayatLama->tanggal_pengambilan)->toDateString()
                                : null;
                            $tglBerobatLama = $riwayatLama->tanggal_berobat
                                ? Carbon::parse($riwayatLama->tanggal_berobat)->toDateString()
                                : null;

                            $dataBerobatBerubah   = $tglBerobatDb && $tglBerobatDb !== $tglBerobatLama;
                            $dataPengambilanBerubah = $tglAmbilDb  && $tglAmbilDb   !== $tglAmbilLama;

                            if ($dataPengambilanBerubah || $dataBerobatBerubah) {
                                // Data berubah → UPDATE riwayat lama
                                $isUpdateFaktur     = true;
                                $riwayatUntukUpdate = $riwayatLama;
                            } else {
                                // Data identik → skip (duplikat murni)
                                $isDuplicate     = true;
                                $duplicateReason = "No. Faktur sudah ada dengan data yang sama: {$noFaktur}";
                            }
                        }
                        // Faktur belum ada → tidak duplikat, akan INSERT

                    } else {
                        // Prioritas 2: cek BPJS + tanggal_berobat
                        $cekPasien = PasienPrb::where('no_kartu_bpjs', $noKartuBpjs)->first();
                        if ($cekPasien && $tglBerobatDb) {
                            $dupExist = RiwayatPengambilanObat::where('pasien_prb_id', $cekPasien->id)
                                ->where('tanggal_berobat', 'like', $tglBerobatDb . '%')
                                ->where('no_faktur', null) // hanya cek transaksi tanpa faktur
                                ->exists();
                            if ($dupExist) {
                                $isDuplicate     = true;
                                $duplicateReason = "Transaksi BPJS {$noKartuBpjs} pada {$tglBerobatDb} sudah ada";
                            }
                        }
                    }
                }

                // Daftarkan kunci hanya jika transaksi benar-benar akan diproses
                // (tglAmbilDb tersedia) — Fix 2: cegah baris tidak lengkap memblokir baris lengkap
                if (!$isDuplicate && $tglAmbilDb) {
                    $processedKeys[] = $batchKey;
                }
            }

            // ── Duplikat murni → simpan ke staging, skip ──────────────────
            if ($isDuplicate) {
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'no_kartu_bpjs'      => $noKartuBpjs,
                    'nama_pasien'        => $namaPasien,
                    'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                    'fktp_asal'          => $fktpAsal,
                    'diagnosis_prb'      => $diagnosisPrb,
                    'no_faktur'          => $noFaktur,
                    'tanggal_berobat'    => $tglBerobatDb,
                    'tanggal_pengambilan'=> $tglAmbilDb,
                    'tanggal_mulai_prb'  => $tglMulaiDb,
                    'status_import'      => 'duplikat',
                    'keterangan_import'  => $duplicateReason,
                ]);
                $jumlahDuplikat++;
                continue;
            }

            // ── Faktur sama tapi data berubah → UPDATE riwayat lama ───────
            if ($isUpdateFaktur && $riwayatUntukUpdate && $tglAmbilDb) {
                try {
                    DB::transaction(function () use (
                        $riwayatUntukUpdate, $tglAmbilDb, $tglBerobatDb
                    ) {
                        // Hitung ulang selisih dan status berdasarkan jadwal_target LAMA
                        // (jadwal_target histori tidak boleh berubah)
                        $jadwalTarget   = Carbon::parse($riwayatUntukUpdate->jadwal_target)->startOfDay();
                        $tglAmbilCarbon = Carbon::parse($tglAmbilDb)->startOfDay();
                        $selisihBaru    = (int) $jadwalTarget->diffInDays($tglAmbilCarbon, false);
                        $batasAwal      = $jadwalTarget->copy()->subDays(3);
                        $batasAkhir     = $jadwalTarget->copy()->addDays(3);
                        $statusBaru     = $tglAmbilCarbon->between($batasAwal, $batasAkhir, true)
                            ? 'Patuh' : 'Tidak Patuh';

                        $riwayatUntukUpdate->update([
                            'tanggal_pengambilan' => $tglAmbilDb,
                            'tanggal_berobat'     => $tglBerobatDb,
                            'selisih_hari'        => $selisihBaru,
                            'status_kepatuhan'    => $statusBaru,
                        ]);

                        // Sinkronkan master pasien jika ini adalah riwayat terbaru
                        $pasien  = $riwayatUntukUpdate->pasienPrb;
                        $terbaru = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
                            ->orderBy('tanggal_pengambilan', 'desc')
                            ->first();

                        if ($terbaru && $terbaru->id === $riwayatUntukUpdate->id) {
                            $jadwalBaru = Carbon::parse($tglAmbilDb)->addDays(KepatuhanService::JATAH_PRB_LAMA)->toDateString();
                            $pasien->update([
                                'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                                'tanggal_jadwal_berikutnya'    => $jadwalBaru,
                                'jenis_prb'                    => 'Lama',
                                'jatah_obat'                   => KepatuhanService::JATAH_PRB_LAMA,
                            ]);
                        }
                    });

                    ResepImport::create([
                        'kode_pasien'        => $kodePasien,
                        'no_kartu_bpjs'      => $noKartuBpjs,
                        'nama_pasien'        => $namaPasien,
                        'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                        'fktp_asal'          => $fktpAsal,
                        'diagnosis_prb'      => $diagnosisPrb,
                        'no_faktur'          => $noFaktur,
                        'tanggal_berobat'    => $tglBerobatDb,
                        'tanggal_pengambilan'=> $tglAmbilDb,
                        'tanggal_mulai_prb'  => $tglMulaiDb,
                        'status_import'      => 'diperbarui',
                        'keterangan_import'  => "Transaksi {$noFaktur} diperbarui: tanggal pengambilan → {$tglAmbilDb}",
                    ]);
                    $jumlahDiperbarui++;

                } catch (\Exception $e) {
                    ResepImport::create([
                        'kode_pasien'        => $kodePasien,
                        'no_kartu_bpjs'      => $noKartuBpjs,
                        'nama_pasien'        => $namaPasien,
                        'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                        'fktp_asal'          => $fktpAsal,
                        'diagnosis_prb'      => $diagnosisPrb,
                        'no_faktur'          => $noFaktur,
                        'tanggal_berobat'    => $tglBerobatDb,
                        'tanggal_pengambilan'=> $tglAmbilDb,
                        'tanggal_mulai_prb'  => $tglMulaiDb,
                        'status_import'      => 'invalid',
                        'keterangan_import'  => 'Gagal update: ' . $e->getMessage(),
                    ]);
                    $jumlahInvalid++;
                }
                continue; // lanjut ke baris berikutnya
            }

            // ----------------------------------------------------------------
            // LANGKAH 4–8: PROSES TRANSAKSI BARU
            //
            // Dibungkus dalam DB::transaction per baris agar:
            //   - Gagal INSERT riwayat tidak membuat master pasien ter-update
            //   - Gagal UPDATE master tidak membuat riwayat setengah tersimpan
            //   - Baris lain dalam batch tidak terdampak jika satu baris gagal
            // ----------------------------------------------------------------
            $keteranganImport = '';
            $isPatientNew     = false;

            try {
                DB::transaction(function () use (
                    $row, $kodePasien, $noKartuBpjs, $namaPasien, $noFaktur,
                    $fktpAsal, $diagnosisPrb, $tglMulaiDb, $tglBerobatDb,
                    $tglAmbilDb, $hasTransaction,
                    &$isPatientNew, &$keteranganImport
                ) {
                    // LANGKAH 4: Cari atau buat master pasien
                    $pasien = null;
                    if (!empty($kodePasien)) {
                        $pasien = PasienPrb::where('kode_pasien', $kodePasien)->first();
                    }
                    if (!$pasien) {
                        $pasien = PasienPrb::where('no_kartu_bpjs', $noKartuBpjs)->first();
                    }

                    if (!$pasien) {
                        // ------------------------------------------------
                        // PASIEN BARU
                        // Profil lengkap dibuat dari data Excel.
                        // Status selalu 'Aktif' — tidak diambil dari Excel.
                        // ------------------------------------------------
                        $isPatientNew = true;
                        $dataPasien   = [
                            'kode_pasien'       => !empty($kodePasien)
                                                    ? $kodePasien
                                                    : PasienPrb::generateKodePasien(),
                            'nama_pasien'       => $namaPasien,
                            'no_kartu_bpjs'     => $noKartuBpjs,
                            'no_whatsapp'       => $row['no_whatsapp'] ?? null,
                            'fktp_asal'         => $fktpAsal,
                            'diagnosis_prb'     => $diagnosisPrb,
                            'tanggal_mulai_prb' => $tglMulaiDb,
                            'status'            => 'Aktif',
                            'alasan_pasif'      => null,
                            'sumber_data'       => 'Import Excel',
                            'tanggal_realisasi' => null,
                        ];

                        if ($hasTransaction && $tglAmbilDb) {
                            $dataPasien['tanggal_pengambilan_terakhir'] = $tglAmbilDb;
                        }

                        $this->setJenisDanJadwal($dataPasien);
                        $pasien = PasienPrb::create($dataPasien);

                        $keteranganImport = 'Pasien baru ditambahkan'
                            . ($hasTransaction ? ' beserta transaksi' : '');

                    } else {
                        // ------------------------------------------------
                        // PASIEN LAMA
                        // Profil (nama, fktp, diagnosis, whatsapp) TIDAK diubah.
                        // Jadwal hanya dimajukan jika transaksi ini lebih baru.
                        //
                        // PENTING: $jadwalTargetSebelumUpdate di-capture SEBELUM
                        // master diupdate agar riwayat menyimpan jadwal siklus
                        // yang sedang direalisasikan, bukan jadwal siklus berikutnya.
                        // ------------------------------------------------
                        $isPatientNew = false;

                        // Tangkap jadwal aktif SEBELUM master dimajukan.
                        // Ini adalah jadwal_target yang harus disimpan ke riwayat.
                        $jadwalTargetSebelumUpdate = $pasien->tanggal_jadwal_berikutnya
                            ? $pasien->tanggal_jadwal_berikutnya->toDateString()
                            : null;

                        if ($hasTransaction && $tglAmbilDb) {
                            $existingTerakhir = $pasien->tanggal_pengambilan_terakhir
                                ? $pasien->tanggal_pengambilan_terakhir->toDateString()
                                : null;

                            if (empty($existingTerakhir)
                                || Carbon::parse($tglAmbilDb)->gte(Carbon::parse($existingTerakhir))
                            ) {
                                $tempData = [
                                    'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                                    'tanggal_mulai_prb'            => $pasien->tanggal_mulai_prb
                                        ? $pasien->tanggal_mulai_prb->toDateString()
                                        : $tglMulaiDb,
                                ];
                                $this->setJenisDanJadwal($tempData);
                                $pasien->update([
                                    'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                                    'jenis_prb'                    => $tempData['jenis_prb'],
                                    'jatah_obat'                   => $tempData['jatah_obat'],
                                    'tanggal_jadwal_berikutnya'    => $tempData['tanggal_jadwal_berikutnya'],
                                ]);
                                $pasien->refresh(); // sync in-memory state
                            }
                        }
                        $keteranganImport = $hasTransaction
                            ? 'Transaksi baru ditambahkan pada pasien lama'
                            : 'Data profil pasien diperbarui (tanpa transaksi)';
                    }

                    // LANGKAH 5–6: Insert riwayat jika ada transaksi
                    if ($hasTransaction && $tglAmbilDb) {
                        // jadwal_target = jadwal aktif pasien SEBELUM dimajukan.
                        // Untuk pasien baru: mulai_prb + 23 hari (jadwal pertama).
                        // Untuk pasien lama: tanggal_jadwal_berikutnya sebelum update.
                        // Fallback jika tidak ada jadwal: tglAmbilDb (selisih=0, Patuh).
                        if ($isPatientNew) {
                            $jadwalTarget = $tglMulaiDb
                                ? Carbon::parse($tglMulaiDb)->addDays(KepatuhanService::JATAH_PRB_BARU)->toDateString()
                                : $tglAmbilDb;
                        } else {
                            // Pasien lama: gunakan jadwal_target yang sudah di-capture
                            // SEBELUM master diupdate ($jadwalTargetSebelumUpdate).
                            // Ini memastikan riwayat mencatat jadwal siklus yang sedang
                            // direalisasikan, bukan jadwal siklus berikutnya (yang sudah
                            // ter-advance oleh update di atas).
                            $jadwalTarget = $jadwalTargetSebelumUpdate ?? $tglAmbilDb;
                        }

                        $tglAmbilCarbon     = Carbon::parse($tglAmbilDb)->startOfDay();
                        $jadwalTargetCarbon = Carbon::parse($jadwalTarget)->startOfDay();

                        $selisihHari = (int) $jadwalTargetCarbon->diffInDays($tglAmbilCarbon, false);

                        $batasAwal  = $jadwalTargetCarbon->copy()->subDays(3);
                        $batasAkhir = $jadwalTargetCarbon->copy()->addDays(3);
                        $statusKepatuhan = $tglAmbilCarbon->between($batasAwal, $batasAkhir, true)
                            ? 'Patuh'
                            : 'Tidak Patuh';

                        RiwayatPengambilanObat::create([
                            'pasien_prb_id'       => $pasien->id,
                            'no_faktur'           => $noFaktur,
                            'tanggal_berobat'     => $tglBerobatDb,
                            'tanggal_pengambilan' => $tglAmbilDb,
                            'jadwal_target'       => $jadwalTarget,
                            'selisih_hari'        => $selisihHari,
                            'status_kepatuhan'    => $statusKepatuhan,
                        ]);
                    }
                }); // akhir DB::transaction

                // Berhasil — simpan ke staging sebagai 'baru'
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'no_kartu_bpjs'      => $noKartuBpjs,
                    'nama_pasien'        => $namaPasien,
                    'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                    'fktp_asal'          => $fktpAsal,
                    'diagnosis_prb'      => $diagnosisPrb,
                    'no_faktur'          => $noFaktur,
                    'tanggal_berobat'    => $tglBerobatDb,
                    'tanggal_pengambilan'=> $tglAmbilDb,
                    'tanggal_mulai_prb'  => $tglMulaiDb,
                    'status_import'      => 'baru',
                    'keterangan_import'  => $keteranganImport,
                ]);

                if ($isPatientNew) {
                    $jumlahBaru++;
                } else {
                    $jumlahDiperbarui++;
                }

            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Race condition: dua request bersamaan dengan faktur yang sama.
                // Transaksi sudah di-rollback oleh DB::transaction.
                // Perlakukan sebagai duplikat.
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'no_kartu_bpjs'      => $noKartuBpjs,
                    'nama_pasien'        => $namaPasien,
                    'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                    'fktp_asal'          => $fktpAsal,
                    'diagnosis_prb'      => $diagnosisPrb,
                    'no_faktur'          => $noFaktur,
                    'tanggal_berobat'    => $tglBerobatDb,
                    'tanggal_pengambilan'=> $tglAmbilDb,
                    'tanggal_mulai_prb'  => $tglMulaiDb,
                    'status_import'      => 'duplikat',
                    'keterangan_import'  => 'Duplikat terdeteksi saat penyimpanan (UNIQUE constraint): '
                                           . $noFaktur,
                ]);
                $jumlahDuplikat++;

            } catch (\Exception $e) {
                // Kesalahan tidak terduga — log dan tandai sebagai invalid
                // agar baris lain tetap diproses.
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'no_kartu_bpjs'      => $noKartuBpjs,
                    'nama_pasien'        => $namaPasien,
                    'no_whatsapp'        => $row['no_whatsapp'] ?? null,
                    'fktp_asal'          => $fktpAsal,
                    'diagnosis_prb'      => $diagnosisPrb,
                    'no_faktur'          => $noFaktur,
                    'tanggal_berobat'    => $tglBerobatDb,
                    'tanggal_pengambilan'=> $tglAmbilDb,
                    'tanggal_mulai_prb'  => $tglMulaiDb,
                    'status_import'      => 'invalid',
                    'keterangan_import'  => 'Kesalahan sistem: ' . $e->getMessage(),
                ]);
                $jumlahInvalid++;
            }

        } // akhir foreach $rows

        return redirect()
            ->route('pasien.index')
            ->with('importResult', [
                'baru'       => $jumlahBaru,
                'diperbarui' => $jumlahDiperbarui,
                'duplikat'   => $jumlahDuplikat,
                'invalid'    => $jumlahInvalid,
            ])
            ->with(
                'success',
                "Import selesai: {$jumlahBaru} data baru, {$jumlahDiperbarui} data diperbarui, {$jumlahDuplikat} duplikat dilewati, {$jumlahInvalid} gagal."
            );
    }

    /**
     * =========================================================
     * EXPORT
     * =========================================================
     */
    public function export(
        Request $request
    ) {
        $tanggalEvaluasi = $request->input(
            'tanggal_evaluasi',
            now()->toDateString()
        );
        $search = $request->input('search', '');
        $filterStatus = $request->input('filter_status', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $filterDiagnosis = $request->input('filter_diagnosis', '');
        $filterFktp = $request->input('filter_fktp', '');
        $filterEarlyWarning = $request->input('filter_early_warning', '');
        $filterPeriode = $request->input('filter_periode', '');

        $query = PasienPrb::with('riwayatPengambilanObat')->orderBy('kode_pasien', 'asc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $searchLike = '%' . strtolower($search) . '%';
                $q->whereRaw('LOWER(nama_pasien) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(kode_pasien) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(no_kartu_bpjs) LIKE ?', [$searchLike]);
            });
        }

        if ($filterStatus) {
            $query->where('status', $filterStatus);
        }

        if ($filterDiagnosis) {
            $query->whereJsonContains('diagnosis_prb', $filterDiagnosis);
        }

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

        $data = $query
            ->get()
            ->map(
                function ($pasien) use (
                    $tanggalEvaluasi,
                    $filterPeriode
                ) {
                    if ($filterPeriode) {
                        $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $tanggalEvaluasi);
                    } else {
                        $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                    }

                    $lastTindakLanjut =
                        $pasien
                            ->riwayatTindakLanjut()
                            ->latest(
                                'tanggal_jam'
                            )
                            ->first();

                    $tglAmbil = (array_key_exists('tanggal_realisasi_aktif', $kepatuhan) && $kepatuhan['tanggal_realisasi_aktif'])
                        ? \Carbon\Carbon::parse($kepatuhan['tanggal_realisasi_aktif'])->format('d/m/Y')
                        : 'Belum diambil';

                    $jadwalAmbil = (array_key_exists('jadwal_target_aktif', $kepatuhan) && $kepatuhan['jadwal_target_aktif'])
                        ? \Carbon\Carbon::parse($kepatuhan['jadwal_target_aktif'])->format('d/m/Y')
                        : '-';

                    $tindakLanjut = $lastTindakLanjut
                        ? "{$lastTindakLanjut->tindakan} - {$lastTindakLanjut->catatan}"
                        : '-';

                    return [
                        'Kode Pasien' => $pasien->kode_pasien,
                        'Nama Pasien' => $pasien->nama_pasien,
                        'Nama FKTP' => $pasien->fktp_asal,
                        'Diagnosis' => is_array($pasien->diagnosis_prb) ? implode(', ', $pasien->diagnosis_prb) : $pasien->diagnosis_prb,
                        'Periode Laporan' => $filterPeriode ? \Carbon\Carbon::parse($filterPeriode . '-01')->translatedFormat('F Y') : 'Semua Periode',
                        'Jadwal Selanjutnya' => $jadwalAmbil,
                        'Kedatangan Pasien' => $tglAmbil,
                        'Status Kepatuhan' => $kepatuhan['status'],
                        'Early Warning' => $kepatuhan['early_warning'] ?? '-',
                        'Keterangan' => $kepatuhan['keterangan'],
                        'Tindak Lanjut' => $tindakLanjut,
                    ];
                }
            );

        if ($filterKepatuhan) {
            $data = $data->filter(fn($p) => $p['Status Kepatuhan'] === $filterKepatuhan)->values();
        }

        if ($filterEarlyWarning) {
            $data = $data->filter(fn($p) => $p['Early Warning'] === $filterEarlyWarning)->values();
        }

        return response()->json(
            $data
        );
    }

    /**
     * =========================================================
     * HELPER:
     * TENTUKAN JENIS PRB DAN JADWAL
     * =========================================================
     *
     * ATURAN GLOBAL (tidak boleh dilanggar di mana pun):
     *
     * 1. PRB BARU — Belum pernah mengambil obat.
     *    Kondisi  : tanggal_pengambilan_terakhir kosong/null
     *    Jatah    : 23 hari
     *    Jadwal   : tanggal_mulai_prb + 23 hari
     *
     * 2. PRB LAMA — Sudah pernah mengambil obat.
     *    Kondisi  : tanggal_pengambilan_terakhir terisi
     *    Jatah    : 30 hari
     *    Jadwal   : tanggal_pengambilan_terakhir + 30 hari
     *
     * SAFEGUARD:
     * - Jika ternyata jatah_obat tidak sesuai jenis, koreksi otomatis.
     * - Jika tanggal_mulai_prb tidak ada (data lama), fallback ke today.
     * - Jenis PRB selalu HANYA Baru atau Lama — tidak ada nilai lain.
     *
     * Fungsi ini dipanggil oleh store(), update(), dan import().
     * Setiap alur yang mengubah tanggal_pengambilan_terakhir WAJIB
     * memanggil fungsi ini agar jenis, jatah, dan jadwal selalu sinkron.
     */
    private function setJenisDanJadwal(
        array &$data
    ): void {
        $tanggalTerakhir = $data['tanggal_pengambilan_terakhir'] ?? null;

        // =====================================================
        // PRB BARU: tidak ada riwayat pengambilan
        // =====================================================
        if (empty($tanggalTerakhir)) {
            $data['jenis_prb']  = 'Baru';
            $data['jatah_obat'] = KepatuhanService::JATAH_PRB_BARU;

            // Fallback: jika tanggal_mulai_prb tidak ada, gunakan today
            $tglMulai = !empty($data['tanggal_mulai_prb'])
                ? $data['tanggal_mulai_prb']
                : Carbon::today()->toDateString();

            $data['tanggal_jadwal_berikutnya'] =
                Carbon::parse($tglMulai)->addDays(KepatuhanService::JATAH_PRB_BARU)->toDateString();

            return;
        }

        // =====================================================
        // PRB LAMA: sudah memiliki riwayat pengambilan
        // =====================================================
        $data['jenis_prb']  = 'Lama';
        $data['jatah_obat'] = KepatuhanService::JATAH_PRB_LAMA;

        $data['tanggal_jadwal_berikutnya'] =
            Carbon::parse($tanggalTerakhir)->addDays(KepatuhanService::JATAH_PRB_LAMA)->toDateString();
    }
}