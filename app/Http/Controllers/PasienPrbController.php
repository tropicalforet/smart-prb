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
            KepatuhanService::tanggalEvaluasiBawaan()
        );

        $search = $request->input('search', '');
        $filterStatus = $request->input('filter_status', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $filterDiagnosis = $this->daftarFilterDiagnosis($request);
        $filterFktp = $request->input('filter_fktp', '');
        $filterPeriode = $request->input('filter_periode', '');
        $filterJenisPrb = $request->input('filter_jenis_prb', '');
        $filterEarlyWarning = $request->input('filter_early_warning', '');
        $filterTindakLanjut = $request->input('filter_tindak_lanjut', '');
        $filterJumlahDiagnosis = $request->input('filter_jumlah_diagnosis', '');
        
        // Sorting parameters
        $sortBy = $request->input('sort_by', 'prioritas_ews'); // default: paling mendesak di atas
        $sortOrder = $request->input('sort_order', 'asc') === 'desc' ? 'desc' : 'asc';

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
            $this->terapkanFilterDiagnosis($query, $filterDiagnosis);
        }

        if ($filterTindakLanjut) {
            $this->terapkanFilterTindakLanjut($query, $filterTindakLanjut);
        }

        if ($filterJumlahDiagnosis) {
            $this->terapkanFilterJumlahDiagnosis($query, $filterJumlahDiagnosis);
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

                        // NOKA + mulai PRB + tanggal ambil → baris gabungan
                        'kunci_kelompok' =>
                            PasienPrb::kunciKelompok($pasien),
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
         *   'Tidak Teratur'       → 'Tidak teratur/Overdue'
         */
        if ($filterEarlyWarning) {
            $matchValues = self::nilaiEarlyWarning($filterEarlyWarning);

            $pasienAll = $pasienAll
                ->filter(fn($p) => in_array($p['early_warning'] ?? '', $matchValues))
                ->values();
        }

        // Satu pasien, beberapa diagnosis, tanggal mulai & ambil sama → satu baris
        $pasienAll = self::gabungBarisDiagnosis($pasienAll);

        /*
         * Sort data pasien — dipilih lewat panah di judul kolom tabel:
         * kode_pasien, diagnosis_prb, tanggal_mulai_prb,
         * tanggal_pengambilan_terakhir, tanggal_jadwal_berikutnya,
         * prioritas_ews (default), status_kepatuhan.
         *
         * Perbandingan natural (PRB-999 sebelum PRB-1000) dan nilai kosong
         * selalu di bawah, baik urutan naik maupun turun.
         */
        $nilaiSort = fn (array $p) => match ($sortBy) {
            'noka'                         => $p['noka'] ?? null,
            'jenis_prb'                    => $p['jenis_prb'] ?? null,
            'fktp_asal'                    => $p['fktp_asal'] ?? null,
            'tanggal_mulai_prb'            => isset($p['tanggal_mulai_prb']) ? substr($p['tanggal_mulai_prb'], 0, 10) : null,
            'tanggal_pengambilan_terakhir' => $p['tanggal_pengambilan_terakhir'] ?? null,
            'tanggal_jadwal_berikutnya'    => substr((string) ($p['jadwal_target_aktif'] ?? $p['tanggal_jadwal_berikutnya'] ?? ''), 0, 10) ?: null,
            'diagnosis_prb'                => implode(', ', (array) ($p['diagnosis_prb'] ?? [])) ?: null,
            'status_kepatuhan'             => $p['status_kepatuhan'] ?? null,
            default                        => $p['kode_pasien'] ?? null,
        };

        // Early Warning: naik = paling mendesak di atas, turun = kebalikannya
        $pasienAll = $sortBy === 'prioritas_ews'
            ? $this->urutkanPrioritasEws($pasienAll, $tanggalEvaluasi)
                ->when($sortOrder === 'desc', fn ($c) => $c->reverse()->values())
            : $pasienAll->sort(function ($a, $b) use ($nilaiSort, $sortOrder) {
            $va = $nilaiSort($a);
            $vb = $nilaiSort($b);

            if ($va === null || $va === '' || $vb === null || $vb === '') {
                return ($va === null || $va === '') <=> ($vb === null || $vb === '');
            }

            $hasil = strnatcasecmp((string) $va, (string) $vb);
            return $sortOrder === 'desc' ? -$hasil : $hasil;
        })->values();

        return Inertia::render(
            'Pasien/Index',
            [
                'pasienList' =>
                    $pasienAll,

                'tanggalEvaluasi' =>
                    $tanggalEvaluasi,

                // Untuk tombol Reset: kembali ke akhir periode penelitian
                'tanggalEvaluasiBawaan' =>
                    KepatuhanService::tanggalEvaluasiBawaan(),

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

                    'filter_tindak_lanjut' =>
                        $filterTindakLanjut,

                    'filter_jumlah_diagnosis' =>
                        $filterJumlahDiagnosis,
                    
                    'sort_by' =>
                        $sortBy,
                    
                    'sort_order' =>
                        $sortOrder,
                ],

                // 9 diagnosis baku + diagnosis lain yang ada di data (mis. dari import)
                'diagnosisOptions' => PasienPrb::DIAGNOSIS_OPTIONS,

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
        $this->rapikanNoWhatsapp($request);
        $this->bakukanFktpInput($request);
        $this->samarkanNamaInput($request);

        $validated = $request->validate([
            // NOKA / NOKA tersandi: format bebas (huruf, angka, spasi)
            'noka' => [
                'required', 'string', 'max:100',
            ],

            // Hanya 8 FKTP penelitian (nama lengkap)
            'fktp_asal' => [
                'required', 'string', Rule::in(PasienPrb::FKTP_OPTIONS),
            ],

            'diagnosis_prb' => [
                'required',
                'array',
                'min:1'
            ],

            // Hanya 9 diagnosis PRB
            'diagnosis_prb.*' => [
                'required',
                'string',
                Rule::in(PasienPrb::DIAGNOSIS_OPTIONS),
            ],

            'tanggal_mulai_prb' =>
                'required|date',

            'tanggal_pengambilan_terakhir' =>
                'nullable|date|before_or_equal:today',

            // Opsional — sudah dirapikan ke 628xxxxxxxxxx sebelum divalidasi
            'no_whatsapp' =>
                ['nullable', 'regex:/^628\d{7,12}$/'],

            // Opsional — sudah disamarkan sebelum divalidasi
            'nama_samaran' =>
                'nullable|string|max:150',

            'status' =>
                'required|in:Aktif,Pasif,Meninggal',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',

            'sumber_data' =>
                'nullable|string',
        ]);

        // Satu data PRB = satu diagnosis: beberapa diagnosis yang dicentang
        // menjadi beberapa data (jadwal & kepatuhan masing-masing).
        $pesan = [];
        foreach ($validated['diagnosis_prb'] as $diagnosis) {
            $pesan[] = $this->simpanSatuDiagnosis(array_merge($validated, ['diagnosis_prb' => [$diagnosis]]));
        }

        return redirect()
            ->back()
            ->with('success', count($pesan) === 1
                ? $pesan[0]
                : count($pesan) . ' data PRB disimpan, satu per diagnosis.');
    }

    /**
     * Simpan satu data PRB (satu NOKA + satu diagnosis): perbarui bila sudah
     * ada, selain itu buat baru. Mengembalikan pesan untuk petugas.
     */
    private function simpanSatuDiagnosis(array $validated): string
    {
        // Data PRB dikenali dari NOKA + diagnosis: NOKA yang sama dengan
        // diagnosis lain menjadi data terpisah (jadwal & kepatuhan sendiri).
        $existing = PasienPrb::cariPerDiagnosis($validated['noka'], $validated['diagnosis_prb']);

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
                    $jenisPrb  = KepatuhanService::tentukanJenisPrb(
                        $existing->tanggal_mulai_prb?->toDateString(),
                        $newTglAmbil,
                        KepatuhanService::tanggalAmbilPertama($existing, $newTglAmbil)
                    );
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

            // Perbarui data profil (fktp, status) jika ada perubahan
            $existing->update([
                'fktp_asal'     => $validated['fktp_asal'],
                'status'        => $validated['status'] ?? $existing->status,
                'alasan_pasif'  => $validated['alasan_pasif'] ?? $existing->alasan_pasif,
                'no_whatsapp'   => $validated['no_whatsapp'] ?? $existing->no_whatsapp,
                'nama_samaran'  => $validated['nama_samaran'] ?? $existing->nama_samaran,
            ]);

            $this->auditLogService->ubahPasien(
                $existing->id,
                $existing->identitas(),
                AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($existing->fresh()))
            );

            return 'Data pasien ' . $existing->identitas() . ' berhasil diperbarui dan jadwal disinkronkan tanpa menduplikasi data.';
        }

        /*
         * Generate kode pasien otomatis untuk pasien baru.
         */
        // Diagnosis lain dari NOKA yang sudah ada → kode yang sama (ID_PRB
        // sama, dibedakan diagnosis); selain itu kode baru.
        $kodeSaudara = PasienPrb::where('noka', $validated['noka'])->orderBy('created_at')->value('kode_pasien');
        $validated['kode_pasien'] = $kodeSaudara ?: PasienPrb::generateKodePasien();

        /*
         * Tentukan PRB Baru/Lama dan jadwal berikutnya.
         * Mulai PRB bulan ini → PRB Baru, sebelumnya → PRB Lama
         */
        $this->setJenisDanJadwal($validated);

        /*
         * Belum ada realisasi
         * saat pasien dibuat (kecuali jika ada tanggal_pengambilan_terakhir).
         */
        $validated['tanggal_realisasi'] = null;

        if ($validated['status'] !== 'Pasif') {
            $validated['alasan_pasif'] = null;
        }

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

        return 'Pasien berhasil ditambahkan.';
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
            KepatuhanService::tanggalEvaluasiBawaan()
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

            'riwayatPengingatWa' =>
                fn ($q) => $q->orderByDesc('dikirim_pada'),
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

        // Tab Histori Pengambilan hanya menampilkan bulan penelitian
        // (status di atas tetap dihitung dari seluruh riwayat).
        $pasien->setRelation(
            'riwayatPengambilanObat',
            $pasien->riwayatPengambilanObat
                ->filter(fn ($r) => KepatuhanService::dalamPeriodePenelitian($r->tanggal_pengambilan?->toDateString()))
                ->values()
        );

        $kelompok = $pasien->kelompok();

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

                // Label periode penelitian untuk tab Histori, mis. "Maret–Mei 2026"
                'periode_penelitian' =>
                    KepatuhanService::labelPeriodePenelitian(),

                // Baris gabungan: semua diagnosis & id data PRB di baris ini
                'anggota_ids' =>
                    $kelompok->pluck('id')->all(),

                'diagnosis_kelompok' =>
                    $kelompok->flatMap(fn ($p) => (array) $p->diagnosis_prb)->unique()->values()->all(),
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

        $this->rapikanNoWhatsapp($request);
        $this->bakukanFktpInput($request);
        $this->samarkanNamaInput($request);

        $validated = $request->validate([
            // NOKA / NOKA tersandi: format bebas (huruf, angka, spasi)
            'noka' => [
                'required', 'string', 'max:100',
            ],

            // Hanya 8 FKTP penelitian (nama lengkap)
            'fktp_asal' => [
                'required', 'string', Rule::in(PasienPrb::FKTP_OPTIONS),
            ],

            // Satu data PRB = satu diagnosis (diagnosis lain lewat Tambah)
            'diagnosis_prb' => [
                'required',
                'array',
                'min:1',
                'max:1',
            ],

            // Hanya 9 diagnosis PRB
            'diagnosis_prb.*' => [
                'required',
                'string',
                Rule::in(PasienPrb::DIAGNOSIS_OPTIONS),
            ],

            'tanggal_mulai_prb' =>
                'required|date',

            'tanggal_pengambilan_terakhir' =>
                'nullable|date|before_or_equal:today',

            // Opsional — sudah dirapikan ke 628xxxxxxxxxx sebelum divalidasi
            'no_whatsapp' =>
                ['nullable', 'regex:/^628\d{7,12}$/'],

            // Opsional — sudah disamarkan sebelum divalidasi
            'nama_samaran' =>
                'nullable|string|max:150',

            'status' =>
                'required|in:Aktif,Pasif,Meninggal',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',
        ]);

        // Baris gabungan (beberapa diagnosis, tanggal sama): data bersama
        // (NOKA, FKTP, mulai PRB, status, WA, nama) diterapkan ke semua
        // diagnosisnya; diagnosis hanya diubah pada data yang dibuka.
        DB::transaction(function () use ($pasien, $validated, $sebelum) {
        foreach ($this->anggotaBaris($pasien) as $anggota) {
            $v = $validated;
            if ($anggota->id !== $pasien->id) {
                $v['diagnosis_prb'] = (array) $anggota->diagnosis_prb;
            }
            $sebelumAnggota = $anggota->id === $pasien->id ? $sebelum : AuditLogService::snapshotPasien($anggota);
            // NOKA + diagnosis harus unik (satu data PRB per diagnosis)
            $bentrok = PasienPrb::where('noka', $v['noka'])
                ->where('kunci_diagnosis', PasienPrb::kunciDiagnosis($v['diagnosis_prb']))
                ->where('id', '!=', $anggota->id)
                ->exists();
            if ($bentrok) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'diagnosis_prb' => 'NOKA ini sudah punya data PRB untuk diagnosis tersebut.',
                ]);
            }

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
                $v[
                    'tanggal_pengambilan_terakhir'
                ] ?? null;

            if (empty($tanggalTerakhir)) {
                $riwayatTerakhir =
                    $anggota
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

                    $v[
                        'tanggal_pengambilan_terakhir'
                    ] = $tanggalTerakhir;
                }
            }

            /*
             * Hitung ulang jenis PRB,
             * jatah obat, dan jadwal.
             */
            $this->setJenisDanJadwal(
                $v,
                $anggota
            );

            // Alasan pasif hanya untuk status Pasif
            if ($v['status'] !== 'Pasif') {
                $v['alasan_pasif'] = null;
            }

            $anggota->update(
                $v
            );

            $this->auditLogService->ubahPasien(
                $anggota->id,
                $anggota->identitas(),
                AuditLogService::selisih($sebelumAnggota, AuditLogService::snapshotPasien($anggota->fresh()))
            );
        }
        });

        return redirect()
            ->back()
            ->with('success', 'Data pasien berhasil diperbarui.');
    }

    /**
     * Filter diagnosis bisa lebih dari satu (ceklis): ?filter_diagnosis[]=Asma&...
     * Teks "Asma" lama (satu diagnosis) tetap diterima.
     *
     * @return string[]
     */
    private function daftarFilterDiagnosis(Request $request): array
    {
        $nilai = $request->input('filter_diagnosis', []);

        return collect(is_array($nilai) ? $nilai : [$nilai])
            ->map(fn ($d) => trim((string) $d))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Pasien (NOKA) yang muncul pada lebih dari satu diagnosis ('lebih_dari_satu')
     * atau hanya satu diagnosis ('satu'). Semua baris diagnosis pasien tersebut
     * ikut tampil, sehingga mudah dibandingkan.
     */
    private function terapkanFilterJumlahDiagnosis($query, string $nilai): void
    {
        $nokaMulti = fn ($q) => $q->select('noka')->from('pasien_prb')
            ->whereNotNull('noka')->groupBy('noka')->havingRaw('COUNT(*) > 1');

        match ($nilai) {
            'lebih_dari_satu' => $query->whereIn('noka', $nokaMulti),
            'satu'            => $query->whereNotIn('noka', $nokaMulti),
            default           => null,
        };
    }

    /**
     * Pasien yang kategori tindak lanjut TERAKHIR-nya = $kategori
     * (sama dengan kolom "Kategori Tindak Lanjut" di export).
     */
    private function terapkanFilterTindakLanjut($query, string $kategori): void
    {
        $query->where(function ($q) {
            $q->select('kategori')
                ->from('riwayat_tindak_lanjut')
                ->whereColumn('riwayat_tindak_lanjut.pasien_prb_id', 'pasien_prb.id')
                ->orderByDesc('tanggal_jam')
                ->limit(1);
        }, $kategori);
    }

    /**
     * Nilai filter Early Warning → nilai early_warning yang cocok:
     *   'Jadwal Mendekat' → 'Jadwal mendekat', 'Jatuh Tempo' → 'Jatuh tempo',
     *   'Belum Mengambil' → 'Belum mengambil, masih toleransi',
     *   'Tidak Teratur'   → 'Tidak teratur/Overdue'
     *
     * @return string[]
     */
    private static function nilaiEarlyWarning(string $filter): array
    {
        return [
            'Jadwal Mendekat' => ['Jadwal mendekat', 'Jadwal Mendekat'],
            'Jatuh Tempo'     => ['Jatuh tempo', 'Jatuh Tempo'],
            'Belum Mengambil' => ['Belum mengambil, masih toleransi', 'Masih Toleransi'],
            'Tidak Teratur'   => ['Tidak teratur/Overdue', 'Tidak Teratur/Overdue'],
        ][$filter] ?? [$filter];
    }

    /** Pasien yang memiliki SALAH SATU diagnosis terpilih. */
    private function terapkanFilterDiagnosis($query, array $diagnosis): void
    {
        $query->where(function ($q) use ($diagnosis) {
            foreach ($diagnosis as $d) {
                $q->orWhereJsonContains('diagnosis_prb', $d);
            }
        });
    }

    /**
     * Prioritas Early Warning — pasien paling mendesak HARI INI di atas,
     * berubah otomatis setiap hari mengikuti tanggal evaluasi:
     *   1. Belum mengambil, masih toleransi (H+1..H+3) — H+3 paling atas
     *   2. Jatuh tempo (H)
     *   3. Jadwal mendekat (H-3..H-1) — H-1 paling atas
     *   4. Tidak teratur/Overdue (≥ H+4) — yang baru lewat paling atas
     *   5. Belum memasuki jadwal — jadwal terdekat paling atas
     *   6. Lainnya, lalu 7. Pasif / Meninggal
     * Seri → kode pasien (natural).
     */
    private function urutkanPrioritasEws($pasienAll, string $tanggalEvaluasi)
    {
        $peringkat = [
            'Belum mengambil, masih toleransi' => 1,
            'Jatuh tempo'                      => 2,
            'Jadwal mendekat'                  => 3,
            'Tidak teratur/Overdue'            => 4,
            'Belum memasuki jadwal'            => 5,
        ];
        $evaluasi = Carbon::parse($tanggalEvaluasi)->startOfDay();

        $kunci = function (array $p) use ($peringkat, $evaluasi): array {
            if (($p['status'] ?? 'Aktif') !== 'Aktif') {
                return [7, 0];
            }
            $rank   = $peringkat[$p['early_warning'] ?? ''] ?? 6;
            $jadwal = substr((string) ($p['jadwal_target_aktif'] ?? $p['tanggal_jadwal_berikutnya'] ?? ''), 0, 10);
            $selisih = $jadwal ? (int) Carbon::parse($jadwal)->diffInDays($evaluasi, false) : 0;

            // Dalam satu peringkat: yang paling dekat batasnya di atas
            return [$rank, $rank === 4 ? $selisih : -$selisih];
        };

        return $pasienAll->sort(function ($a, $b) use ($kunci) {
            return ($kunci($a) <=> $kunci($b))
                ?: strnatcasecmp((string) ($a['kode_pasien'] ?? ''), (string) ($b['kode_pasien'] ?? ''));
        })->values();
    }

    /**
     * Nama pasien tidak pernah disimpan utuh: apa pun yang dikirim form
     * disamarkan dulu ("Rizka" → "R***a").
     */
    private function samarkanNamaInput(Request $request): void
    {
        if ($request->has('nama_samaran')) {
            $request->merge([
                'nama_samaran' => PasienPrb::samarkanNama($request->input('nama_samaran')),
            ]);
        }
    }

    /**
     * Rapikan No. WhatsApp (opsional) sebelum validasi agar petugas boleh
     * mengetik 0812-..., +62 812 ..., dsb.
     */
    /**
     * Gabungkan baris data PRB satu pasien yang kunci_kelompok-nya sama
     * (NOKA, tanggal mulai PRB, dan seluruh tanggal pengambilan sama) menjadi
     * satu baris berisi beberapa diagnosis. anggota_ids = id data PRB di
     * baris itu (aksi pada baris berlaku untuk semuanya).
     */
    public static function gabungBarisDiagnosis(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        return $rows->groupBy(fn ($p) => $p['kunci_kelompok'] ?? $p['id'])
            ->map(function ($g) {
                $g = $g->sortBy(fn ($p) => PasienPrb::urutanDiagnosis($p))->values();
                $baris = $g->first();
                $baris['diagnosis_prb'] = $g->flatMap(fn ($p) => (array) $p['diagnosis_prb'])->unique()->values()->all();
                $baris['anggota_ids'] = $g->pluck('id')->all();
                if ($g->count() > 1) {
                    $baris['status_kepatuhan'] = KepatuhanService::statusPasien($g->pluck('status_kepatuhan'));
                }
                unset($baris['kunci_kelompok']);

                return $baris;
            })
            ->values();
    }

    /** Data PRB yang dikenai aksi: satu baris gabungan = semua diagnosisnya. */
    private function anggotaBaris(PasienPrb $pasien): \Illuminate\Support\Collection
    {
        return $pasien->kelompok();
    }

    /** Singkatan FKTP ("KPMA") → nama lengkap sebelum divalidasi terhadap 8 FKTP. */
    private function bakukanFktpInput(Request $request): void
    {
        if ($request->filled('fktp_asal')) {
            $request->merge([
                'fktp_asal' => PasienPrb::bakukanFktp($request->input('fktp_asal')) ?? $request->input('fktp_asal'),
            ]);
        }
    }

    private function rapikanNoWhatsapp(Request $request): void
    {
        if ($request->has('no_whatsapp')) {
            $request->merge([
                'no_whatsapp' => PasienPrb::normalisasiNoWhatsapp($request->input('no_whatsapp')),
            ]);
        }
    }

    /**
     * =========================================================
     * DESTROY
     * =========================================================
     */
    public function destroy(
        PasienPrb $pasien
    ) {
        // Baris gabungan → semua diagnosisnya ikut dihapus
        foreach ($this->anggotaBaris($pasien) as $anggota) {
            $this->auditLogService->hapusPasien(
                $anggota->id,
                $anggota->identitas(),
                AuditLogService::selisih(AuditLogService::snapshotPasien($anggota), [])
            );
            $anggota->delete();
        }

        return redirect()
            ->back()
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
        // Hanya pasien yang dipilih (daftar id). Tidak ada opsi "hapus semua"
        // agar seluruh data tidak bisa terhapus lewat satu permintaan.
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'uuid',
        ]);

        // Baris gabungan yang dicentang → semua diagnosisnya ikut terhapus
        $ids = PasienPrb::whereIn('id', $validated['ids'])->get()
            ->flatMap(fn (PasienPrb $p) => $this->anggotaBaris($p)->pluck('id'))
            ->unique()->values()->all();
        $query = PasienPrb::whereIn('id', $ids);

        // Identitas dicatat sebelum dihapus (rincian audit log)
        $terhapus = (clone $query)->orderBy('kode_pasien')->get()->map->identitas()->all();
        $count    = count($terhapus);
        $query->delete();

        $this->auditLogService->hapusMassal($count, $terhapus);

        return redirect()
            ->back()
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
    private function updateRealisasiEditSatu(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'riwayat_id'        => 'required|uuid',
            'tanggal_realisasi' => 'required|date|before_or_equal:today',
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

    /*
     * =========================================================
     * AKSI PADA BARIS GABUNGAN
     * =========================================================
     *
     * Satu baris di Data Pasien bisa berisi beberapa diagnosis (data PRB
     * dengan NOKA, mulai PRB, dan tanggal ambil yang sama). Aksi di bawah
     * dijalankan untuk SEMUA diagnosis di baris itu agar tanggalnya tetap
     * sama. Data yang dibuka diproses lebih dulu; pesannya yang ditampilkan.
     */

    /** @return array{0: PasienPrb, 1: \Illuminate\Support\Collection<int, PasienPrb>} data utama + diagnosis lain di barisnya */
    private function utamaDanLainnya(PasienPrb $pasien): array
    {
        return [$pasien, $this->anggotaBaris($pasien)->reject(fn (PasienPrb $p) => $p->id === $pasien->id)->values()];
    }

    /** Riwayat diagnosis lain yang tanggal ambilnya sama dengan riwayat data utama. */
    private function riwayatSetanggal(PasienPrb $anggota, ?RiwayatPengambilanObat $acuan): ?RiwayatPengambilanObat
    {
        $tanggal = $acuan?->tanggal_pengambilan?->toDateString();

        return $tanggal
            ? RiwayatPengambilanObat::where('pasien_prb_id', $anggota->id)->where('tanggal_pengambilan', 'like', $tanggal . '%')->first()
            : null;
    }

    public function updateRealisasi(Request $request, PasienPrb $pasien)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $hasil = $this->updateRealisasiSatu($request, $utama);
        foreach ($lain as $p) $this->updateRealisasiSatu($request, $p);

        return $hasil;
    }

    public function updateRealisasiEdit(Request $request, PasienPrb $pasien)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $acuan = RiwayatPengambilanObat::where('pasien_prb_id', $utama->id)->find($request->input('riwayat_id'));
        $hasil = $this->updateRealisasiEditSatu($request, $utama);
        foreach ($lain as $p) {
            if ($r = $this->riwayatSetanggal($p, $acuan)) {
                $this->updateRealisasiEditSatu((clone $request)->merge(['riwayat_id' => $r->id]), $p);
            }
        }

        return $hasil;
    }

    public function hapusRealisasi(PasienPrb $pasien, string $riwayat)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $acuan = RiwayatPengambilanObat::where('pasien_prb_id', $utama->id)->find($riwayat);
        $hasil = $this->hapusRealisasiSatu($utama, $riwayat);
        if ($acuan && !RiwayatPengambilanObat::whereKey($acuan->id)->exists()) { // utama berhasil dihapus
            foreach ($lain as $p) {
                if ($r = $this->riwayatSetanggal($p, $acuan)) $this->hapusRealisasiSatu($p, $r->id);
            }
        }

        return $hasil;
    }

    public function updateJadwal(Request $request, PasienPrb $pasien)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $hasil = $this->updateJadwalSatu($request, $utama);
        foreach ($lain as $p) $this->updateJadwalSatu($request, $p);

        return $hasil;
    }

    public function updateTerakhirAmbil(Request $request, PasienPrb $pasien)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $hasil = $this->updateTerakhirAmbilSatu($request, $utama);
        foreach ($lain as $p) $this->updateTerakhirAmbilSatu($request, $p);

        return $hasil;
    }

    public function updateStatus(Request $request, PasienPrb $pasien)
    {
        [$utama, $lain] = $this->utamaDanLainnya($pasien);
        $hasil = $this->updateStatusSatu($request, $utama);
        foreach ($lain as $p) $this->updateStatusSatu($request, $p);

        return $hasil;
    }

    /**
     * Hapus realisasi pengambilan obat yang salah input (hanya realisasi
     * manual terbaru — lihat KepatuhanService::hapusRealisasi).
     */
    private function hapusRealisasiSatu(PasienPrb $pasien, string $riwayat)
    {
        $sebelum = AuditLogService::snapshotPasien($pasien);
        $hasil   = $this->kepatuhanService->hapusRealisasi($pasien, $riwayat);

        if (isset($hasil['error'])) {
            return redirect()->back()->with('info', $hasil['error']);
        }

        $dihapus = $hasil['riwayat'];
        $this->auditLogService->hapusRealisasi(
            $pasien->id,
            $pasien->identitas(),
            $dihapus->tanggal_pengambilan->toDateString(),
            array_merge([
                ['kolom' => 'Tanggal Pengambilan', 'lama' => $dihapus->tanggal_pengambilan->format('d/m/Y'), 'baru' => null],
                ['kolom' => 'Status Kepatuhan',    'lama' => $dihapus->status_kepatuhan, 'baru' => null],
                ['kolom' => 'No. Faktur',          'lama' => $dihapus->no_faktur, 'baru' => null],
            ], AuditLogService::selisih($sebelum, AuditLogService::snapshotPasien($pasien->fresh())))
        );

        return redirect()->back()->with('success', 'Pengambilan obat ' . $dihapus->tanggal_pengambilan->format('d/m/Y') . ' dihapus.');
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
    private function updateRealisasiSatu(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_realisasi' => 'required|date|before_or_equal:today',
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
    private function updateJadwalSatu(
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
    private function updateTerakhirAmbilSatu(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_pengambilan_terakhir' => 'required|date|before_or_equal:today',
        ]);

        $tglTerakhir = $validated['tanggal_pengambilan_terakhir'];
        $sebelum     = AuditLogService::snapshotPasien($pasien);

        // Catat pengambilan obat ke riwayat, evaluasi kepatuhan (Patuh/Tidak Teratur)
        // terhadap jadwal target sebelumnya, dan otomatis majukan jadwal berikutnya +30 hari (PRB Lama).
        $hasil = $this->kepatuhanService->prosesRealisasi($pasien, $tglTerakhir);

        if (!empty($hasil['duplikat'])) {
            // Jika tanggal sudah pernah tercatat di riwayat, pastikan master tetap sinkron.
            // Jenis PRB dari bulan mulai PRB vs bulan tanggal realisasi.
            $jenisPrb  = KepatuhanService::tentukanJenisPrb(
                $pasien->tanggal_mulai_prb?->toDateString(),
                $tglTerakhir,
                KepatuhanService::tanggalAmbilPertama($pasien, $tglTerakhir)
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
    private function updateStatusSatu(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'status' =>
                'required|in:Aktif,Pasif,Meninggal',

            'alasan_pasif' =>
                'nullable|required_if:status,Pasif|string',
        ]);

        /*
         * Alasan pasif hanya untuk status Pasif
         * (Aktif / Meninggal tidak punya alasan pasif).
         */
        if ($validated['status'] !== 'Pasif') {
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
            'data.*.nama_samaran'                => 'nullable|string|max:255',
            'data.*.fktp_asal'                   => 'nullable|string|max:255',
            'data.*.diagnosis_prb'               => 'nullable',
            'data.*.tanggal_mulai_prb'           => 'nullable|string',
            'data.*.tanggal_berobat'             => 'nullable|string',
            'data.*.tanggal_pengambilan'         => 'nullable|string',
            'data.*.tanggal_pengambilan_terakhir'=> 'nullable|string',
            // Penanda baris Excel ("Sheet#12") untuk ringkasan impor
            'data.*.baris_excel'                 => 'nullable|string|max:150',
        ]);

        // Satu data PRB = satu diagnosis: baris berisi beberapa diagnosis
        // ("HT, DM") dipisah menjadi beberapa baris dengan tanggal yang sama.
        $rows = collect($validated['data'])->flatMap(function ($row) {
            $diagnosis = $row['diagnosis_prb'] ?? null;
            $daftar    = is_array($diagnosis) ? $diagnosis : explode(',', (string) $diagnosis);
            // Dibakukan dulu (BPH → Hipertensi) agar "HT, BPH" menjadi satu data,
            // bukan dua transaksi untuk diagnosis yang sama.
            $daftar    = array_values(array_unique(array_filter(array_map(
                fn ($d) => PasienPrb::bakukanDiagnosis((string) $d) ?? trim((string) $d), $daftar
            ))));

            // No. Faktur harus unik per riwayat → diagnosis ke-2 dst. diberi akhiran
            return count($daftar) > 1
                ? array_map(fn ($d, $i) => array_merge($row, [
                    'diagnosis_prb' => [$d],
                    'no_faktur'     => !empty($row['no_faktur']) && $i > 0 ? $row['no_faktur'] . '-' . ($i + 1) : ($row['no_faktur'] ?? null),
                ]), $daftar, array_keys($daftar))
                : [$row];
        })->all();

        // Counter ringkasan untuk response
        $jumlahBaru       = 0;
        $jumlahDiperbarui = 0;
        $jumlahDuplikat   = 0;
        $jumlahInvalid    = 0;

        // Pelacak kunci transaksi dalam batch ini untuk mencegah
        // duplikasi antar baris dalam satu file yang sama.
        // Key: 'faktur_{noFaktur}' atau 'noka_tgl_{noka}_{tanggalBerobat}'
        $processedKeys = [];

        // Ringkasan per pasien (bukan per transaksi): NOKA + diagnosis yang
        // tersimpan, dan baris Excel yang gagal. Hasil tiap baris dicatat
        // dari perubahan penghitung saat baris berikutnya mulai diproses.
        $pasienTersimpan = [];   // [noka => [diagnosis => true]]
        $barisGagal      = [];   // ["Sheet#12" => true]
        $barisSebelumnya = null; // [row, baru+diperbarui, invalid]
        $catatHasilBaris = function () use (&$barisSebelumnya, &$pasienTersimpan, &$barisGagal, &$jumlahBaru, &$jumlahDiperbarui, &$jumlahInvalid) {
            if (!$barisSebelumnya) return;
            [$r, $tersimpan, $gagal] = $barisSebelumnya;
            $noka = trim((string) ($r['noka'] ?? ''));
            if ($jumlahBaru + $jumlahDiperbarui > $tersimpan && $noka !== '') {
                $d = (array) ($r['diagnosis_prb'] ?? []);
                $pasienTersimpan[$noka][PasienPrb::bakukanDiagnosis((string) ($d[0] ?? '')) ?? '-'] = true;
            }
            if ($jumlahInvalid > $gagal && !empty($r['baris_excel'])) {
                $barisGagal[$r['baris_excel']] = true;
            }
            $barisSebelumnya = null;
        };

        foreach ($rows as $row) {
            $catatHasilBaris();
            $barisSebelumnya = [$row, $jumlahBaru + $jumlahDiperbarui, $jumlahInvalid];
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
            // Singkatan apotek ("KPMA", "B SBY") → nama lengkap; null = di luar 8 FKTP
            $fktpBaku    = PasienPrb::bakukanFktp($fktpAsal);
            // Nama sudah disamarkan di browser; disamarkan ulang sebagai pengaman
            $namaSamaran = PasienPrb::samarkanNama($row['nama_samaran'] ?? null);

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
            } elseif ($fktpBaku === null) {
                $invalidReasons[] = "FKTP {$fktpAsal} bukan salah satu dari 8 FKTP penelitian";
            } else {
                $fktpAsal = $fktpBaku;
            }
            if (empty($diagnosisPrb)) {
                $invalidReasons[] = 'Diagnosis PRB wajib diisi';
            } else {
                // Hanya 9 diagnosis PRB yang diteliti; nama dibakukan ("DM" → Diabetes Melitus)
                $bukanPrb = array_values(array_filter($diagnosisPrb, fn ($d) => PasienPrb::bakukanDiagnosis($d) === null));
                if ($bukanPrb) {
                    $invalidReasons[] = 'Diagnosis ' . implode(', ', $bukanPrb) . ' bukan salah satu dari 9 diagnosis PRB';
                } else {
                    $diagnosisPrb = array_values(array_unique(array_map([PasienPrb::class, 'bakukanDiagnosis'], $diagnosisPrb)));
                }
            }
            // Kode pasien dari file (ID_PRB) tidak boleh menunjuk pasien lain —
            // pasien dicari lewat kode lebih dulu, jadi transaksi akan salah tempat.
            if (!empty($kodePasien) && !empty($noka)) {
                $nokaPemilikKode = PasienPrb::where('kode_pasien', $kodePasien)->value('noka');
                if ($nokaPemilikKode !== null && $nokaPemilikKode !== $noka) {
                    $invalidReasons[] = "Kode Pasien {$kodePasien} sudah dipakai pasien dengan NOKA lain";
                }
            }
            // Pengambilan obat tidak mungkin terjadi di masa depan
            // (biasanya salah tahun pada kolom bulan).
            if ($tglAmbilDb && $tglAmbilDb > Carbon::today()->toDateString()) {
                $invalidReasons[] = 'Tanggal pengambilan melewati hari ini';
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
                    // Satu data PRB per diagnosis: tanggal sama untuk diagnosis lain bukan duplikat
                    $batchKey .= '_' . PasienPrb::kunciDiagnosis($diagnosisPrb);
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

                            if ($dataPengambilanBerubah && $tglAmbilLama
                                && substr($tglAmbilLama, 0, 7) !== substr($tglAmbilDb, 0, 7)) {
                                // Faktur sama, pengambilan di BULAN LAIN (mis. 31/03 lalu
                                // 01/05) → transaksi baru; histori bulan sebelumnya tetap
                                // ada dan tidak tertimpa.
                                $noFaktur = $noFaktur . '-' . str_replace('-', '', $tglAmbilDb);
                                if (RiwayatPengambilanObat::where('no_faktur', $noFaktur)->exists()) {
                                    $isDuplicate     = true;
                                    $duplicateReason = "No. Faktur sudah ada dengan data yang sama: {$noFaktur}";
                                }
                            } elseif ($dataPengambilanBerubah || $dataBerobatBerubah) {
                                // Data berubah di bulan yang sama (koreksi) → UPDATE riwayat lama
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
                        $cekPasien = PasienPrb::cariPerDiagnosis($noka, $diagnosisPrb);
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
                            ? 'Patuh' : 'Tidak Teratur';

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
                            // Jenis PRB dari bulan mulai PRB vs bulan pengambilan.
                            // Mulai PRB dari master; fallback ke baris Excel.
                            $tglMulaiAcuan = $pasien->tanggal_mulai_prb?->toDateString() ?? $tglMulaiDb;
                            $jenisPrb      = KepatuhanService::tentukanJenisPrb(
                                $tglMulaiAcuan,
                                $tglAmbilDb,
                                KepatuhanService::tanggalAmbilPertama($pasien, $tglAmbilDb)
                            );
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
                    $row, $kodePasien, $noka, $noFaktur, $namaSamaran,
                    $fktpAsal, $diagnosisPrb, $tglMulaiDb, $tglBerobatDb,
                    $tglAmbilDb, $hasTransaction,
                    &$isPatientNew, &$keteranganImport
                ) {
                    // LANGKAH 4: Cari atau buat master pasien.
                    // Data PRB = NOKA + diagnosis: baris dengan NOKA sama tapi
                    // diagnosis lain menjadi data terpisah (jadwal sendiri).
                    $pasien = PasienPrb::cariPerDiagnosis($noka, $diagnosisPrb);

                    if (!$pasien) {
                        // ------------------------------------------------
                        // PASIEN BARU
                        // Profil lengkap dibuat dari data Excel.
                        // Status selalu 'Aktif' — tidak diambil dari Excel.
                        // ------------------------------------------------
                        $isPatientNew = true;
                        $dataPasien   = [
                            // ID_PRB sama untuk diagnosis kedua → kode sama, dibedakan diagnosis
                            'kode_pasien'       => !empty($kodePasien)
                                                    ? $kodePasien
                                                    : PasienPrb::generateKodePasien(),
                            'noka'     => $noka,
                            'nama_samaran'      => $namaSamaran,
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

                        // Nama samaran dilengkapi bila pasien lama belum punya
                        if ($namaSamaran && !$pasien->nama_samaran) {
                            $pasien->update(['nama_samaran' => $namaSamaran]);
                        }

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
                                $this->setJenisDanJadwal($tempData, $pasien);
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
                            : 'Tidak Teratur';

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
        $catatHasilBaris();

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
                // NOKA → diagnosis yang tersimpan (untuk hitung pasien unik)
                'pasien'       => array_map('array_keys', $pasienTersimpan),
                'baris_gagal'  => array_keys($barisGagal),
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
     * Simpan baris Excel yang tidak valid (sudah diperiksa di browser) ke
     * tabel buffer resep_import, beserta alasannya. Tidak masuk ke data
     * pasien maupun riwayat — hanya catatan agar jumlah "Gagal karena
     * tidak valid" sama dengan rincian saat impor dan dapat ditelusuri.
     */
    public function importTidakValid(Request $request)
    {
        $validated = $request->validate([
            'data'                       => 'required|array|min:1|max:1000',
            'data.*.kode_pasien'         => 'nullable|string|max:50',
            'data.*.noka'                => 'nullable|string|max:50',
            'data.*.fktp_asal'           => 'nullable|string|max:255',
            'data.*.diagnosis_prb'       => 'nullable',
            'data.*.no_faktur'           => 'nullable|string|max:100',
            'data.*.tanggal_mulai_prb'   => 'nullable|string',
            'data.*.tanggal_berobat'     => 'nullable|string',
            'data.*.tanggal_pengambilan' => 'nullable|string',
            'data.*.keterangan'          => 'required|string|max:2000',
        ]);

        $tanggal = function ($v) {
            if (empty($v)) return null;
            try {
                return Carbon::parse($v)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        foreach ($validated['data'] as $row) {
            $diagnosis = $row['diagnosis_prb'] ?? [];
            $diagnosis = is_array($diagnosis) ? $diagnosis : array_filter(array_map('trim', explode(',', (string) $diagnosis)));

            ResepImport::create([
                'kode_pasien'         => $row['kode_pasien'] ?? null,
                'noka'                => trim((string) ($row['noka'] ?? '')) ?: '-',
                'fktp_asal'           => trim((string) ($row['fktp_asal'] ?? '')) ?: '-',
                'diagnosis_prb'       => array_values($diagnosis),
                'no_faktur'           => $row['no_faktur'] ?? null,
                'tanggal_berobat'     => $tanggal($row['tanggal_berobat'] ?? null),
                'tanggal_pengambilan' => $tanggal($row['tanggal_pengambilan'] ?? null),
                'tanggal_mulai_prb'   => $tanggal($row['tanggal_mulai_prb'] ?? null),
                'status_import'       => 'invalid',
                'keterangan_import'   => $row['keterangan'],
            ]);
        }

        return response()->json(['invalid' => count($validated['data'])]);
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
            KepatuhanService::tanggalEvaluasiBawaan()
        );
        $search = $request->input('search', '');
        $filterStatus = $request->input('filter_status', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $filterDiagnosis = $this->daftarFilterDiagnosis($request);
        $filterFktp = $request->input('filter_fktp', '');
        $filterEarlyWarning = $request->input('filter_early_warning', '');
        $filterTindakLanjut = $request->input('filter_tindak_lanjut', '');
        $filterJumlahDiagnosis = $request->input('filter_jumlah_diagnosis', '');
        $filterPeriode = $request->input('filter_periode', '');

        $query = PasienPrb::with('riwayatPengambilanObat')->orderBy('kode_pasien', 'asc');

        // Export pasien terpilih (centang di tabel): hanya id tersebut, ditambah
        // sheet Histori Pengambilan. Filter tabel tidak dipakai untuk memilih
        // pasien; periode & tanggal evaluasi tetap dipakai untuk status kepatuhan.
        $ids = $request->isMethod('post')
            ? $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'uuid'])['ids']
            : null;

        // Histori pengambilan ikut dikirim untuk pasien terpilih, dan untuk
        // Export PDF biasa (?dengan_histori=1). Export Excel biasa tetap 1 sheet.
        $denganHistori = $ids !== null || $request->boolean('dengan_histori');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
            $search = $filterStatus = $filterKepatuhan = $filterFktp = $filterEarlyWarning = $filterTindakLanjut = $filterJumlahDiagnosis = '';
            $filterDiagnosis = [];
        }

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
            $this->terapkanFilterDiagnosis($query, $filterDiagnosis);
        }

        if ($filterTindakLanjut) {
            $this->terapkanFilterTindakLanjut($query, $filterTindakLanjut);
        }

        if ($filterJumlahDiagnosis) {
            $this->terapkanFilterJumlahDiagnosis($query, $filterJumlahDiagnosis);
        }

        if ($filterFktp) {
            $query->where('fktp_asal', $filterFktp);
        }

        if ($filterPeriode && $ids === null) {
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
                    $filterPeriode,
                    $denganHistori
                ) {
                    if ($filterPeriode) {
                        $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $tanggalEvaluasi);
                    } else {
                        $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                    }

                    // Tindak lanjut terakhir — Hasil Tindakan + Catatan, sama
                    // seperti yang tampil di tab Tindak Lanjut pada web
                    $tindakLanjutTerakhir = $pasien
                        ->riwayatTindakLanjut()
                        ->latest('tanggal_jam')
                        ->first();

                    $tglAmbil = (array_key_exists('tanggal_realisasi_aktif', $kepatuhan) && $kepatuhan['tanggal_realisasi_aktif'])
                        ? \Carbon\Carbon::parse($kepatuhan['tanggal_realisasi_aktif'])->format('d/m/Y')
                        : 'Belum diambil';

                    $jadwalAmbil = (array_key_exists('jadwal_target_aktif', $kepatuhan) && $kepatuhan['jadwal_target_aktif'])
                        ? \Carbon\Carbon::parse($kepatuhan['jadwal_target_aktif'])->format('d/m/Y')
                        : '-';

                    $baris = [
                        'Kode Pasien' => $pasien->kode_pasien,
                        'NOKA' => $pasien->noka,
                        'Nama Pasien' => $pasien->nama_samaran ?: '-',
                        'Nama FKTP' => $pasien->fktp_asal,
                        'Diagnosis' => is_array($pasien->diagnosis_prb) ? implode(', ', $pasien->diagnosis_prb) : $pasien->diagnosis_prb,
                        'Periode Laporan' => $filterPeriode ? \Carbon\Carbon::parse($filterPeriode . '-01')->translatedFormat('F Y') : 'Semua Periode',
                        'Jadwal Selanjutnya' => $jadwalAmbil,
                        'Kedatangan Pasien' => $tglAmbil,
                        'Status Kepatuhan' => $kepatuhan['status'],
                        'Early Warning' => $kepatuhan['early_warning'] ?? '-',
                        'Kategori Tindak Lanjut' => $tindakLanjutTerakhir?->kategori ?: '-',
                        'Hasil Tindak Lanjut' => $tindakLanjutTerakhir?->tindakan ?: '-',
                        'Catatan' => $tindakLanjutTerakhir?->catatan ?: '-',
                        // untuk menggabung baris (lihat di bawah); tidak ikut diekspor
                        '_kelompok' => PasienPrb::kunciKelompok($pasien),
                        '_urut' => PasienPrb::urutanDiagnosis($pasien),
                    ];

                    if ($denganHistori) {
                        $baris['_histori'] = $this->historiUntukExport($pasien);
                    }

                    return $baris;
                }
            );

        if ($filterKepatuhan) {
            $data = $data->filter(fn($p) => $p['Status Kepatuhan'] === $filterKepatuhan)->values();
        }

        if ($filterEarlyWarning) {
            $cocok = self::nilaiEarlyWarning($filterEarlyWarning);
            $data  = $data->filter(fn($p) => in_array($p['Early Warning'], $cocok, true))->values();
        }

        // Ekspor ikut tampilan: diagnosis satu pasien dengan tanggal mulai & ambil
        // yang sama menjadi satu baris (histori sama, diambil sekali).
        $data = $data->groupBy('_kelompok')->map(function ($g) {
            $baris = $g->first();
            $baris['Diagnosis'] = $g->sortBy('_urut')->pluck('Diagnosis')->unique()->implode(', ');
            return collect($baris)->except(['_kelompok', '_urut'])->all();
        })->values();

        if (!$denganHistori) {
            return response()->json(
                $data
            );
        }

        // Sheet/tabel 1: satu baris per pasien. Sheet/tabel 2 "Histori Pengambilan":
        // satu baris per pengambilan milik pasien tersebut.
        $histori = $data->flatMap(fn($p) => $p['_histori'])->values();
        $pasien  = $data->map(fn($p) => collect($p)->except('_histori')->all())->values();

        return response()->json([
            'pasien'  => $pasien,
            'histori' => $histori,
        ]);
    }

    /**
     * Histori pengambilan satu pasien untuk export, urut dari yang paling
     * lama — sama dengan tab Histori Pengambilan (satu No. Faktur = satu transaksi).
     */
    private function historiUntukExport(PasienPrb $pasien): array
    {
        return $pasien->riwayatPengambilanObat
            ->filter(fn($r) => KepatuhanService::dalamPeriodePenelitian($r->tanggal_pengambilan?->toDateString()))
            ->sortBy(fn($r) => $r->tanggal_pengambilan?->toDateString() . '|' . $r->created_at)
            ->unique(fn($r) => $r->no_faktur ?: 'tgl_' . ($r->tanggal_berobat ?? $r->tanggal_pengambilan)?->toDateString())
            ->values()
            ->map(function ($r, $i) use ($pasien) {
                $selisih = (int) $r->selisih_hari;

                return [
                    'Kode Pasien'      => $pasien->kode_pasien,
                    'NOKA'             => $pasien->noka,
                    'Nama Pasien'      => $pasien->nama_samaran ?: '-',
                    'Nama FKTP'        => $pasien->fktp_asal,
                    'Pengambilan Ke'   => $i + 1,
                    'Tanggal Ambil'    => $r->tanggal_pengambilan?->format('d/m/Y') ?? '-',
                    'Jadwal Ambil'     => $r->jadwal_target?->format('d/m/Y') ?? '-',
                    'Keterangan'       => $selisih > 0
                        ? "Terlambat {$selisih} hari"
                        : ($selisih < 0 ? abs($selisih) . ' hari lebih awal' : 'Tepat waktu'),
                    'Status Kepatuhan' => $r->status_kepatuhan,
                ];
            })
            ->all();
    }

    /**
     * =========================================================
     * HELPER:
     * TENTUKAN JENIS PRB DAN JADWAL
     * =========================================================
     *
     * ATURAN GLOBAL (tidak boleh dilanggar di mana pun):
     * Jenis dari KepatuhanService::tentukanJenisPrb() — bulan mulai PRB
     * dibandingkan bulan pengambilan terakhir (atau bulan ini jika belum ambil).
     *
     * 1. PRB BARU — mulai PRB di bulan acuan.
     *    Jatah    : 23 hari
     *    Jadwal   : (tanggal_pengambilan_terakhir ?: tanggal_mulai_prb) + 23 hari
     *
     * 2. PRB LAMA — mulai PRB sebelum bulan acuan (atau tanpa tanggal mulai).
     *    Jatah    : 30 hari
     *    Jadwal   : (tanggal_pengambilan_terakhir ?: tanggal_mulai_prb) + 30 hari
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
        ?PasienPrb $pasien = null
    ): void {
        $tanggalTerakhir = $data['tanggal_pengambilan_terakhir'] ?? null;

        // =====================================================
        // TENTUKAN JENIS PRB
        //   Bulan mulai PRB vs bulan pengambilan terakhir
        //   (bulan ini jika belum pernah ambil).
        // =====================================================
        $jenisPrb = KepatuhanService::tentukanJenisPrb(
            $data['tanggal_mulai_prb'] ?? null,
            $tanggalTerakhir,
            KepatuhanService::tanggalAmbilPertama($pasien, $tanggalTerakhir)
        );

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