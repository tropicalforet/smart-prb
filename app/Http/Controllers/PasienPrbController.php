<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Services\AuditLogService;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PasienPrbController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService,
        private AuditLogService  $auditLogService
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
        $filterJenisPrb = $request->input('filter_jenis_prb', '');
        $filterEarlyWarning = $request->input('filter_early_warning', '');
        
        // Sorting parameters
        $sortBy = $request->input('sort_by', 'urgensi'); // default: urgensi
        $sortOrder = $request->input('sort_order', 'desc'); // asc or desc

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
        $periodes = $this->kepatuhanService->daftarPeriode();

        $query = PasienPrb::with([
            'riwayatPengambilanObat' => fn($q) =>
                $q->orderBy('tanggal_pengambilan', 'desc'),
        ]);

        /*
         * Pencarian:
         * - Kode pasien
         * - NOKA
         */
        if ($search) {
            $query->where(function ($q) use ($search) {
                $searchLike = '%' . strtolower($search) . '%';

                $q->whereRaw(
                        'LOWER(kode_pasien) LIKE ?',
                        [$searchLike]
                    )
                    ->orWhereRaw(
                        'LOWER(noka) LIKE ?',
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
         * Filter jenis PRB (Baru / Lama)
         */
        if ($filterJenisPrb) {
            $query->where('jenis_prb', $filterJenisPrb);
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

                // withoutRelations: riwayat hanya untuk perhitungan status.
                // Modal detail mengambil riwayat sendiri via GET /pasien/{id},
                // jadi tidak perlu dikirim untuk setiap baris tabel.
                return array_merge(
                    $pasien->withoutRelations()->toArray(),
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

        /*
         * Filter early warning (kategori EWS)
         * Dilakukan setelah status dihitung karena early_warning adalah computed field.
         *
         * Mapping filter value → early_warning values:
         *   'Jadwal Mendekat'   → 'Jadwal mendekat'
         *   'Jatuh Tempo'       → 'Jatuh tempo'
         *   'Belum Mengambil'   → 'Belum mengambil, masih toleransi'
         *   'Tidak Patuh'       → 'Tidak patuh/Overdue'
         */
        if ($filterEarlyWarning) {
            $ewMap = [
                'Jadwal Mendekat' => ['Jadwal mendekat', 'Jadwal Mendekat'],
                'Jatuh Tempo'     => ['Jatuh tempo', 'Jatuh Tempo'],
                'Belum Mengambil' => ['Belum mengambil, masih toleransi', 'Masih Toleransi'],
                'Tidak Patuh'     => ['Tidak patuh/Overdue', 'Tidak Patuh/Overdue'],
            ];
            $matchValues = $ewMap[$filterEarlyWarning] ?? [$filterEarlyWarning];

            $pasienAll = $pasienAll
                ->filter(fn($p) => in_array($p['early_warning'] ?? '', $matchValues))
                ->values();
        }

        /*
         * Sort data pasien berdasarkan pilihan user.
         * 
         * Opsi sorting:
         * - urgensi: default, berdasarkan selisih hari (overdue paling atas)
         * - kode_pasien: berdasarkan kode pasien alfanumerik
         * - nama_pasien: berdasarkan nama pasien
         * - tanggal_mulai_prb: tanggal mulai program PRB
         * - tanggal_pengambilan_terakhir: terakhir ambil obat
         * - tanggal_jadwal_berikutnya: jadwal pengambilan berikutnya
         */
        if ($sortBy === 'urgensi') {
            // Sorting default: urgensi jadwal pengambilan
            $pasienAll = $pasienAll->sort(function ($a, $b) use ($sortOrder) {
                // Pasif selalu di bawah
                $aPasif = ($a['status'] ?? '') === 'Pasif';
                $bPasif = ($b['status'] ?? '') === 'Pasif';
                if ($aPasif !== $bPasif) {
                    return $aPasif ? 1 : -1;
                }

                // Tidak punya jadwal → di bawah yang punya jadwal
                $aHasJadwal = !empty($a['jadwal_target_aktif']) || !empty($a['tanggal_jadwal_berikutnya']);
                $bHasJadwal = !empty($b['jadwal_target_aktif']) || !empty($b['tanggal_jadwal_berikutnya']);
                if ($aHasJadwal !== $bHasJadwal) {
                    return $aHasJadwal ? -1 : 1;
                }

                // Selisih hari: yang paling besar (paling terlambat / overdue) di atas
                $aSelisih = $a['selisih_hari_evaluasi'] ?? -999;
                $bSelisih = $b['selisih_hari_evaluasi'] ?? -999;
                
                $result = $bSelisih <=> $aSelisih;
                return $sortOrder === 'asc' ? -$result : $result;
            })->values();
        } else {
            // Sorting berdasarkan kolom spesifik
            $pasienAll = $pasienAll->sortBy(function ($pasien) use ($sortBy) {
                $value = match($sortBy) {
                    'kode_pasien' => $pasien['kode_pasien'] ?? '',
                    'nama_pasien' => strtolower($pasien['nama_pasien'] ?? ''),
                    'tanggal_mulai_prb' => $pasien['tanggal_mulai_prb'] ?? '9999-12-31',
                    'tanggal_pengambilan_terakhir' => $pasien['tanggal_pengambilan_terakhir'] ?? '9999-12-31',
                    'tanggal_jadwal_berikutnya' => $pasien['jadwal_target_aktif'] ?? $pasien['tanggal_jadwal_berikutnya'] ?? '9999-12-31',
                    default => $pasien['kode_pasien'] ?? '',
                };
                
                return $value;
            }, SORT_REGULAR, $sortOrder === 'desc')->values();
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

                    'filter_jenis_prb' =>
                        $filterJenisPrb,

                    'filter_early_warning' =>
                        $filterEarlyWarning,
                    
                    'sort_by' =>
                        $sortBy,
                    
                    'sort_order' =>
                        $sortOrder,
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
            // NOKA / NOKA tersandi: format bebas (huruf, angka, spasi)
            'noka' => [
                'required', 'string', 'max:100',
            ],

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
                'nullable|date',

            'status' =>
                'required|in:Aktif,Pasif',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',

            'sumber_data' =>
                'nullable|string',
        ]);

        // Cek apakah pasien sudah terdaftar sebelumnya berdasarkan NOKA
        $existing = PasienPrb::where('noka', $validated['noka'])->first();

        if ($existing) {
            $sebelum = AuditLogService::snapshotPasien($existing);

            // Pasien sudah terdaftar: JANGAN BUAT DUPLIKAT di tabel pasien_prb.
            // Jika ada tanggal pengambilan obat baru yang dimasukkan, catat sebagai transaksi baru
            // dan evaluasi kepatuhan terhadap jadwal sebelumnya.
            $newTglAmbil = $validated['tanggal_pengambilan_terakhir'] ?? null;

            if (!empty($newTglAmbil)) {
                $hasil = $this->kepatuhanService->prosesRealisasi($existing, $newTglAmbil);

                if (!empty($hasil['duplikat'])) {
                    // Jika tanggal sudah pernah tercatat, pastikan data master tetap sinkron.
                    // Load riwayat untuk tentukan jenis PRB (Baru jika belum ada riwayat, Lama jika sudah ada)
                    $existing->load('riwayatPengambilanObat');
                    $jenisPrb  = KepatuhanService::tentukanJenisPrb($existing);
                    $jatahObat = $jenisPrb === 'Baru'
                        ? KepatuhanService::JATAH_PRB_BARU
                        : KepatuhanService::JATAH_PRB_LAMA;

                    $existing->update([
                        'tanggal_pengambilan_terakhir' => $newTglAmbil,
                        'jenis_prb'                    => $jenisPrb,
                        'jatah_obat'                   => $jatahObat,
                        'tanggal_jadwal_berikutnya'    => Carbon::parse($newTglAmbil)->addDays($jatahObat)->toDateString(),
                    ]);
                }
            }

            // Perbarui data profil (fktp, diagnosis, status) jika ada perubahan
            $existing->update([
                'fktp_asal'     => $validated['fktp_asal'],
                'diagnosis_prb' => $validated['diagnosis_prb'],
                'status'        => $validated['status'] ?? $existing->status,
                'alasan_pasif'  => $validated['alasan_pasif'] ?? $existing->alasan_pasif,
            ]);

            $this->auditLogService->ubahPasien(
                $existing->id,
                $existing->identitas(),
                AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($existing->fresh()))
            );

            return redirect()
                ->route('pasien.index')
                ->with(
                    'success',
                    'Data pasien ' . $existing->identitas() . ' berhasil diperbarui dan jadwal disinkronkan tanpa menduplikasi data.'
                );
        }

        /*
         * Generate kode pasien otomatis untuk pasien baru.
         */
        $validated['kode_pasien'] =
            PasienPrb::generateKodePasien();

        /*
         * Tentukan PRB Baru/Lama dan jadwal berikutnya.
         * Pasien baru (belum ada riwayat) → PRB Baru
         */
        $this->setJenisDanJadwal($validated, null);

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

        $this->auditLogService->tambahPasien(
            $pasien->id,
            $pasien->identitas(),
            AuditLogService::selisih([], AuditLogService::snapshotPasien($pasien->fresh()))
        );

        return redirect()
            ->route('pasien.index')
            ->with('success', 'Pasien berhasil ditambahkan.');
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
        $sebelum = AuditLogService::snapshotPasien($pasien);

        $validated = $request->validate([
            // NOKA / NOKA tersandi: format bebas (huruf, angka, spasi)
            'noka' => [
                'required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('pasien_prb', 'noka')->ignore($pasien->id),
            ],

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
                'nullable|date',

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

        $this->auditLogService->ubahPasien(
            $pasien->id,
            $pasien->identitas(),
            AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($pasien->fresh()))
        );

        return redirect()
            ->back()
            ->with('success', 'Data pasien berhasil diperbarui.');
    }

    /**
     * =========================================================
     * DESTROY
     * =========================================================
     */
    public function destroy(
        PasienPrb $pasien
    ) {
        $this->auditLogService->hapusPasien(
            $pasien->id,
            $pasien->identitas(),
            AuditLogService::selisih(AuditLogService::snapshotPasien($pasien), [])
        );

        $pasien->delete();

        return redirect()
            ->route('pasien.index')
            ->with('success', 'Pasien berhasil dihapus.');
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

        $query = !empty($validated['all'])
            ? PasienPrb::query()
            : PasienPrb::whereIn('id', $validated['ids'] ?? []);

        // Identitas dicatat sebelum dihapus (rincian audit log)
        $terhapus = (clone $query)->orderBy('kode_pasien')->get()->map->identitas()->all();
        $count    = count($terhapus);
        $query->delete();

        $this->auditLogService->hapusMassal($count, $terhapus);

        return redirect()
            ->route('pasien.index')
            ->with('success', "$count pasien berhasil dihapus.");
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

        $sebelum     = AuditLogService::snapshotPasien($pasien);
        $riwayatLama = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)->find($validated['riwayat_id']);

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

        $this->auditLogService->editRealisasi(
            $pasien->id,
            $pasien->identitas(),
            $validated['tanggal_realisasi'],
            AuditLogService::rincianRealisasi($pasien, $sebelum, $validated['tanggal_realisasi'], [
                'tanggal' => $riwayatLama?->tanggal_pengambilan,
                'status'  => $riwayatLama?->status_kepatuhan,
            ])
        );

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

        $sebelum = AuditLogService::snapshotPasien($pasien);

        $hasil = $this->kepatuhanService
            ->prosesRealisasi(
                $pasien,
                $validated['tanggal_realisasi']
            );

        if ($hasil['duplikat']) {
            return redirect()
                ->back()
                ->with('info', 'Realisasi pada tanggal tersebut sudah tercatat sebelumnya.');
        }

        $this->auditLogService->catatRealisasi(
            $pasien->id,
            $pasien->identitas(),
            $validated['tanggal_realisasi'],
            AuditLogService::rincianRealisasi($pasien, $sebelum, $validated['tanggal_realisasi'])
        );

        return redirect()
            ->back()
            ->with('success', 'Realisasi pengambilan obat berhasil dicatat.');
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

        $sebelum = AuditLogService::snapshotPasien($pasien);

        $pasien->update([
            'tanggal_jadwal_berikutnya' => $validated['tanggal_jadwal_berikutnya']
        ]);

        $this->auditLogService->ubahJadwal(
            $pasien->id,
            $pasien->identitas(),
            AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($pasien->fresh()))
        );

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
            'tanggal_pengambilan_terakhir' => 'required|date',
        ]);

        $tglTerakhir = $validated['tanggal_pengambilan_terakhir'];
        $sebelum     = AuditLogService::snapshotPasien($pasien);

        // Catat pengambilan obat ke riwayat, evaluasi kepatuhan (Patuh/Tidak Patuh)
        // terhadap jadwal target sebelumnya, dan otomatis majukan jadwal berikutnya +30 hari (PRB Lama).
        $hasil = $this->kepatuhanService->prosesRealisasi($pasien, $tglTerakhir);

        if (!empty($hasil['duplikat'])) {
            // Jika tanggal sudah pernah tercatat di riwayat, pastikan master tetap sinkron.
            // Jenis PRB ditentukan dari tanggal_mulai_prb vs bulan tanggal realisasi.
            $jenisPrb  = KepatuhanService::tentukanJenisPrb(
                $pasien->tanggal_mulai_prb?->toDateString(),
                $tglTerakhir
            );
            $jatahObat = $jenisPrb === 'Baru' ? KepatuhanService::JATAH_PRB_BARU : KepatuhanService::JATAH_PRB_LAMA;

            $pasien->update([
                'tanggal_pengambilan_terakhir' => $tglTerakhir,
                'jenis_prb'                    => $jenisPrb,
                'jatah_obat'                   => $jatahObat,
                'tanggal_jadwal_berikutnya'    => Carbon::parse($tglTerakhir)->addDays($jatahObat)->toDateString(),
            ]);
        }

        $this->auditLogService->catatRealisasi(
            $pasien->id,
            $pasien->identitas(),
            $tglTerakhir,
            AuditLogService::rincianRealisasi($pasien, $sebelum, $tglTerakhir)
        );

        return redirect()
            ->back()
            ->with(
                'success',
                'Tanggal terakhir ambil obat berhasil dicatat. Status kepatuhan dievaluasi dan jadwal berikutnya telah disinkronkan.'
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

        $sebelum = AuditLogService::snapshotPasien($pasien);

        $pasien->update(
            $validated
        );

        $this->auditLogService->ubahStatusPasien(
            $pasien->id,
            $pasien->identitas(),
            $validated['status'],
            AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($pasien->fresh()))
        );

        return redirect()
            ->back()
            ->with('success', 'Status pasien berhasil diperbarui.');
    }

    /**
     * =========================================================
     * IMPORT EXCEL
     * =========================================================
     *
     * Kolom Excel yang didukung (sesuai template):
     *   No. Faktur / No. Transaksi Apotek  → no_faktur (Prioritas 1 duplikat check)
     *   Kode Pasien                         → kode_pasien
     *   NOKA / NOKA Tersandi                → noka
     *   (Nama Pasien & No. HP tidak diterima — ketentuan etik penelitian)
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
     *   Profil pasien (fktp, diagnosis) TIDAK diupdate untuk pasien lama.
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
            'data.*.noka'               => 'nullable|string|max:50',
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
        // Key: 'faktur_{noFaktur}' atau 'noka_tgl_{noka}_{tanggalBerobat}'
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

            $noka = !empty($row['noka'])
                ? trim((string)$row['noka'])
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
            if (empty($noka)) {
                $invalidReasons[] = 'NOKA / NOKA Tersandi wajib diisi';
            }
            if (empty($fktpAsal)) {
                $invalidReasons[] = 'FKTP Asal wajib diisi';
            }
            if (empty($diagnosisPrb)) {
                $invalidReasons[] = 'Diagnosis PRB wajib diisi';
            }

            if (!empty($invalidReasons)) {
                // Simpan ke staging sebagai invalid — tidak perlu transaksi DB
                ResepImport::create([
                    'kode_pasien'        => $kodePasien,
                    'noka'      => $noka ?? '-',
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
            // Prioritas 2 — NOKA + tanggal_berobat (jika no_faktur kosong):
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
                    // Tanpa tanggal_berobat (mis. data apotek per bulan), kunci memakai
                    // tanggal pengambilan agar beberapa pengambilan satu pasien tidak
                    // saling dianggap duplikat.
                    $batchKey = $tglBerobatDb
                        ? 'noka_berobat_' . $noka . '_' . $tglBerobatDb
                        : 'noka_ambil_' . $noka . '_' . $tglAmbilDb;
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
                        // Prioritas 2: cek NOKA + tanggal_berobat
                        $cekPasien = PasienPrb::where('noka', $noka)->first();
                        if ($cekPasien && ($tglBerobatDb || $tglAmbilDb)) {
                            // Ada tanggal_berobat → cocokkan tanggal_berobat;
                            // tidak ada → cocokkan tanggal_pengambilan.
                            [$kolomTgl, $nilaiTgl] = $tglBerobatDb
                                ? ['tanggal_berobat', $tglBerobatDb]
                                : ['tanggal_pengambilan', $tglAmbilDb];

                            $dupExist = RiwayatPengambilanObat::where('pasien_prb_id', $cekPasien->id)
                                ->where($kolomTgl, 'like', $nilaiTgl . '%')
                                ->where('no_faktur', null) // hanya cek transaksi tanpa faktur
                                ->exists();
                            if ($dupExist) {
                                $isDuplicate     = true;
                                $duplicateReason = "Transaksi NOKA {$noka} pada {$nilaiTgl} sudah ada";
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
                    'noka'      => $noka,
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
                        $riwayatUntukUpdate, $tglAmbilDb, $tglBerobatDb, $tglMulaiDb
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
                            // Jenis PRB ditentukan dari tanggal_mulai_prb vs bulan saat ini.
                            // Gunakan tanggal_mulai_prb dari master pasien jika ada,
                            // fallback ke nilai dari baris Excel ($tglMulaiDb).
                            $tglMulaiAcuan = $pasien->tanggal_mulai_prb?->toDateString() ?? $tglMulaiDb;
                            $jenisPrb      = KepatuhanService::tentukanJenisPrb($tglMulaiAcuan);
                            $jatahObat     = $jenisPrb === 'Baru'
                                ? KepatuhanService::JATAH_PRB_BARU
                                : KepatuhanService::JATAH_PRB_LAMA;
                            $jadwalBaru    = Carbon::parse($tglAmbilDb)->addDays($jatahObat)->toDateString();

                            $pasien->update([
                                'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                                'tanggal_jadwal_berikutnya'    => $jadwalBaru,
                                'jenis_prb'                    => $jenisPrb,
                                'jatah_obat'                   => $jatahObat,
                            ]);
                        }
                    });

                    ResepImport::create([
                        'kode_pasien'        => $kodePasien,
                        'noka'      => $noka,
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
                        'noka'      => $noka,
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
                    $row, $kodePasien, $noka, $noFaktur,
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
                        $pasien = PasienPrb::where('noka', $noka)->first();
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
                            'noka'     => $noka,
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
                            // Transaksi pertama yang tercatat di sistem.
                            // Dinilai terhadap jadwal pertama PRB (mulai + 23 hari) HANYA
                            // jika jadwal itu belum lewat lebih dari satu siklus (30 hari).
                            // Lewat lebih jauh berarti ada siklus di antaranya yang tidak
                            // tercatat (mis. mulai 2018 atau Januari, data apotek baru ada
                            // mulai Maret) → transaksi ini menjadi titik awal (baseline):
                            // jadwal_target = tanggal ambil, selisih 0.
                            $jadwalPertama = $tglMulaiDb
                                ? Carbon::parse($tglMulaiDb)->addDays(KepatuhanService::JATAH_PRB_BARU)
                                : null;
                            $jadwalTarget = ($jadwalPertama
                                && $jadwalPertama->diffInDays(Carbon::parse($tglAmbilDb), false) <= KepatuhanService::JATAH_PRB_LAMA)
                                ? $jadwalPertama->toDateString()
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
                    'noka'      => $noka,
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
                    'noka'      => $noka,
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
                    'noka'      => $noka,
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

        $this->auditLogService->importExcel(
            $jumlahBaru, $jumlahDiperbarui, $jumlahDuplikat, $jumlahInvalid
        );

        // Impor bertahap dari frontend (file besar dikirim per potongan
        // agar tidak melewati batas waktu request) → kembalikan JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'baru'       => $jumlahBaru,
                'diperbarui' => $jumlahDiperbarui,
                'duplikat'   => $jumlahDuplikat,
                'invalid'    => $jumlahInvalid,
            ]);
        }

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
                $q->whereRaw('LOWER(kode_pasien) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(noka) LIKE ?', [$searchLike]);
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
                        'NOKA' => $pasien->noka,
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
        array &$data,
        ?PasienPrb $pasienExisting = null
    ): void {
        $tanggalTerakhir = $data['tanggal_pengambilan_terakhir'] ?? null;

        // =====================================================
        // TENTUKAN JENIS PRB
        //
        // **ATURAN BARU:**
        //   PRB BARU = Belum pernah ambil obat (tidak ada riwayat)
        //   PRB LAMA = Sudah pernah ambil obat (ada riwayat)
        //
        // Jika ini pasien existing (update/import duplikat), cek riwayatnya.
        // Jika pasien baru (create), belum ada riwayat → PRB Baru.
        // =====================================================
        $jenisPrb = KepatuhanService::tentukanJenisPrb($pasienExisting);

        $data['jenis_prb'] = $jenisPrb;

        if ($jenisPrb === 'Baru') {
            // =====================================================
            // PRB BARU: jadwal pertama = tanggal_pengambilan_terakhir + 23 hari
            // atau tanggal_mulai_prb + 23 hari jika belum ada pengambilan
            // =====================================================
            $data['jatah_obat'] = KepatuhanService::JATAH_PRB_BARU;

            $tglMulaiPrb = $data['tanggal_mulai_prb'] ?? null;
            $acuanJadwal = !empty($tanggalTerakhir) ? $tanggalTerakhir : $tglMulaiPrb;

            $data['tanggal_jadwal_berikutnya'] = !empty($acuanJadwal)
                ? Carbon::parse($acuanJadwal)->addDays(KepatuhanService::JATAH_PRB_BARU)->toDateString()
                : null;

        } else {
            // =====================================================
            // PRB LAMA: jadwal berikutnya = tanggal_pengambilan_terakhir + 30 hari
            //
            // Jika belum ada tanggal_pengambilan_terakhir, fallback ke
            // tanggal_mulai_prb + 30 hari agar jadwal tetap terdefinisi.
            // =====================================================
            $data['jatah_obat'] = KepatuhanService::JATAH_PRB_LAMA;

            $tglMulaiPrb = $data['tanggal_mulai_prb'] ?? null;
            $acuanJadwal = !empty($tanggalTerakhir) ? $tanggalTerakhir : $tglMulaiPrb;

            $data['tanggal_jadwal_berikutnya'] = !empty($acuanJadwal)
                ? Carbon::parse($acuanJadwal)->addDays(KepatuhanService::JATAH_PRB_LAMA)->toDateString()
                : null;
        }
    }
}