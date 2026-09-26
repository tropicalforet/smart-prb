<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

        // Dapatkan list periode dari riwayat untuk dropdown (berdasarkan tanggal_pengambilan)
        $periodes = \App\Models\RiwayatPengambilanObat::select('tanggal_pengambilan')
            ->whereNotNull('tanggal_pengambilan')
            ->get()
            ->map(function ($row) {
                return \Carbon\Carbon::parse($row->tanggal_pengambilan)->format('Y-m');
            })
            ->filter()
            ->unique()
            ->sortDesc()
            ->map(function ($period) {
                return [
                    'value' => $period,
                    'label' => \Carbon\Carbon::parse($period . '-01')->translatedFormat('F Y'),
                ];
            })->values()->toArray();

        $query = PasienPrb::with('riwayatPengambilanObat')
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
         * Hitung status kepatuhan
         * berdasarkan tanggal evaluasi.
         */
        $pasienAll = $query
            ->get()
            ->map(function ($pasien) use (
                $tanggalEvaluasi,
                $filterPeriode
            ) {
                if ($filterPeriode) {
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode);
                } else {
                    $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                }

                return array_merge(
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

                        'jadwal_target_aktif' => $filterPeriode 
                            ? $kepatuhan['jadwal_target_aktif'] 
                            : ($kepatuhan['jadwal_target_aktif'] ?? $pasien->tanggal_jadwal_berikutnya?->toDateString()),

                        'tanggal_realisasi_aktif' => $filterPeriode
                            ? $kepatuhan['tanggal_realisasi_aktif']
                            : ($kepatuhan['tanggal_realisasi_aktif'] ?? $pasien->tanggal_realisasi?->toDateString()),
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

            'no_kartu_bpjs' =>
                'required|digits:13',

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
            
            'diagnosis_prb.*' => [
                Rule::in(
                    PasienPrb::DIAGNOSIS_OPTIONS
                ),
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
         * Logika yang sama dengan Import Excel:
         * Jika ada tanggal pengambilan terakhir saat input manual, 
         * catat sebagai transaksi pertama di riwayat agar muncul di filter periode.
         */
        if (!empty($validated['tanggal_pengambilan_terakhir'])) {
            $tglAmbilDb = Carbon::parse($validated['tanggal_pengambilan_terakhir'])->toDateString();
            \App\Models\RiwayatPengambilanObat::create([
                'pasien_prb_id' => $pasien->id,
                'no_faktur' => 'MANUAL-' . date('Ymd') . '-' . $pasien->id,
                'tanggal_berobat' => $tglAmbilDb,
                'tanggal_pengambilan' => $tglAmbilDb,
                'jadwal_target' => $tglAmbilDb, // Anggap patuh (on-time) karena ini data base awal
                'selisih_hari' => 0,
                'status_kepatuhan' => 'Patuh',
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
     */
    public function show(
        Request $request,
        PasienPrb $pasien
    ) {
        $tanggalEvaluasi = $request->input(
            'tanggal_evaluasi',
            now()->toDateString()
        );

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

        $kepatuhan =
            $this->kepatuhanService
                ->tentukanStatusKepatuhan(
                    $pasien,
                    $tanggalEvaluasi
                );

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

            'no_kartu_bpjs' =>
                'required|digits:13',

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
            
            'diagnosis_prb.*' => [
                Rule::in(
                    PasienPrb::DIAGNOSIS_OPTIONS
                ),
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
     * UPDATE REALISASI (Step 1)
     * =========================================================
     *
     * Menyimpan tanggal realisasi pada pasien.
     * Tanggal ini akan tampil di kolom "Tanggal Ambil Obat".
     * Status Kepatuhan akan langsung dihitung.
     *
     * Setelah disimpan, siklus berikutnya BELUM dihitung.
     * Siklus baru dihitung ketika konfirmasiRealisasi dipanggil.
     */
    public function updateRealisasi(
        Request $request,
        PasienPrb $pasien
    ) {
        $validated = $request->validate([
            'tanggal_realisasi' =>
                'required|date',
        ]);

        // Simpan tanggal realisasi dan jalankan prosesRealisasi
        // yang akan menyimpan riwayat + memajukan siklus
        $this->kepatuhanService
            ->prosesRealisasi(
                $pasien,
                $validated['tanggal_realisasi']
            );

        return redirect()
            ->back()
            ->with(
                'success',
                'Realisasi pengambilan obat berhasil dicatat.'
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
     * Kolom Excel:
     *
     * 1. Kode Pasien
     * 2. Nama Pasien
     * 3. No. Kartu BPJS
     * 4. No. WhatsApp
     * 5. FKTP Asal
     * 6. Diagnosis PRB
     * 7. Tanggal Mulai PRB
     * 8. Pengambilan Terakhir
     *
     * STATUS TIDAK DIAMBIL DARI EXCEL.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array|min:1',
            'data.*.no_faktur' => 'nullable|string|max:100',
            'data.*.no_transaksi' => 'nullable|string|max:100',
            'data.*.kode_pasien' => 'nullable|string|max:50',
            'data.*.nama_pasien' => 'nullable|string|max:255',
            'data.*.no_kartu_bpjs' => 'nullable|string|max:50',
            'data.*.no_whatsapp' => 'nullable|string|max:20',
            'data.*.fktp_asal' => 'nullable|string|max:255',
            'data.*.diagnosis_prb' => 'nullable',
            'data.*.tanggal_mulai_prb' => 'nullable|string',
            'data.*.tanggal_berobat' => 'nullable|string',
            'data.*.tanggal_pengambilan' => 'nullable|string',
            'data.*.tanggal_pengambilan_terakhir' => 'nullable|string',
        ]);

        $rows = $validated['data'];

        $jumlahBaru = 0;
        $jumlahDuplikat = 0;
        $jumlahInvalid = 0;

        $processedFakturs = [];
        $processedBpjsDates = [];

        foreach ($rows as $row) {
            $noFaktur = !empty($row['no_faktur']) ? trim((string)$row['no_faktur']) : null;
            if (!$noFaktur && !empty($row['no_transaksi'])) {
                $noFaktur = trim((string)$row['no_transaksi']);
            }

            $noKartuBpjs = !empty($row['no_kartu_bpjs']) ? trim((string)$row['no_kartu_bpjs']) : null;
            $namaPasien = !empty($row['nama_pasien']) ? trim((string)$row['nama_pasien']) : null;
            $fktpAsal = !empty($row['fktp_asal']) ? trim((string)$row['fktp_asal']) : null;
            
            $diagnosisPrb = [];
            if (!empty($row['diagnosis_prb']) && is_array($row['diagnosis_prb'])) {
                $diagnosisPrb = $row['diagnosis_prb'];
            } elseif (!empty($row['diagnosis_prb']) && is_string($row['diagnosis_prb'])) {
                $diagnosisPrb = array_map('trim', explode(',', $row['diagnosis_prb']));
            }

            $tanggalMulaiPrb = !empty($row['tanggal_mulai_prb']) ? $row['tanggal_mulai_prb'] : null;
            $tanggalBerobat = !empty($row['tanggal_berobat']) ? $row['tanggal_berobat'] : null;
            $tanggalPengambilan = !empty($row['tanggal_pengambilan']) 
                ? $row['tanggal_pengambilan'] 
                : (!empty($row['tanggal_pengambilan_terakhir']) ? $row['tanggal_pengambilan_terakhir'] : null);

            if (!$tanggalBerobat) {
                $tanggalBerobat = $tanggalPengambilan ?? $tanggalMulaiPrb;
            }

            if (!$tanggalPengambilan) {
                $tanggalPengambilan = $tanggalBerobat ?? $tanggalMulaiPrb;
            }

            $tglBerobatDb = $tanggalBerobat ? Carbon::parse($tanggalBerobat)->toDateString() : null;
            $tglAmbilDb = $tanggalPengambilan ? Carbon::parse($tanggalPengambilan)->toDateString() : $tglBerobatDb;
            $tglMulaiDb = $tanggalMulaiPrb ? Carbon::parse($tanggalMulaiPrb)->toDateString() : null;

            // 1. Simpan ke tabel staging (buffer) `resep_import`
            $resepImport = ResepImport::create([
                'no_faktur' => $noFaktur,
                'no_kartu_bpjs' => $noKartuBpjs ?? '-',
                'nama_pasien' => $namaPasien ?? '-',
                'no_whatsapp' => !empty($row['no_whatsapp']) ? $row['no_whatsapp'] : null,
                'fktp_asal' => $fktpAsal ?? '-',
                'diagnosis_prb' => $diagnosisPrb,
                'tanggal_berobat' => $tglBerobatDb ?? now()->toDateString(),
                'tanggal_pengambilan' => $tglAmbilDb,
                'tanggal_mulai_prb' => $tglMulaiDb,
                'status_import' => 'pending',
                'keterangan_import' => null,
            ]);

            // Validasi kelengkapan data wajib
            $invalidReasons = [];
            if (empty($namaPasien)) {
                $invalidReasons[] = 'Nama Pasien wajib diisi';
            }
            if (empty($noKartuBpjs) || strlen($noKartuBpjs) !== 13) {
                $invalidReasons[] = 'No. Kartu BPJS harus 13 digit';
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
                $resepImport->update([
                    'status_import' => 'invalid',
                    'keterangan_import' => implode(', ', $invalidReasons),
                ]);
                $jumlahInvalid++;
                continue;
            }

            // 2. Pengecekan Duplikasi
            $isDuplicate = false;
            $duplicateReason = '';

            // Pengecekan A: No. Faktur / No. Transaksi (Identitas Transaksi Utama)
            if (!empty($noFaktur)) {
                if (in_array($noFaktur, $processedFakturs, true)) {
                    $isDuplicate = true;
                    $duplicateReason = "No. Faktur/Transaksi ({$noFaktur}) duplikat dalam file ini.";
                }

                if (!$isDuplicate) {
                    $existsInStaging = ResepImport::where('no_faktur', $noFaktur)
                        ->where('status_import', 'baru')
                        ->where('id', '!=', $resepImport->id)
                        ->exists();
                    if ($existsInStaging) {
                        $isDuplicate = true;
                        $duplicateReason = "No. Faktur/Transaksi ({$noFaktur}) sudah pernah di-import sebelumnya.";
                    }
                }

                if (!$isDuplicate) {
                    $existsInRiwayat = RiwayatPengambilanObat::where('no_faktur', $noFaktur)->exists();
                    if ($existsInRiwayat) {
                        $isDuplicate = true;
                        $duplicateReason = "No. Faktur/Transaksi ({$noFaktur}) sudah ada di riwayat apotek.";
                    }
                }
            }

            // Pengecekan B: No. Kartu BPJS + Tanggal Berobat (Pengecekan Tambahan)
            if (!$isDuplicate && !empty($noKartuBpjs) && !empty($tglBerobatDb)) {
                $bpjsKey = $noKartuBpjs . '_' . $tglBerobatDb;
                if (in_array($bpjsKey, $processedBpjsDates, true)) {
                    $isDuplicate = true;
                    $duplicateReason = "No. BPJS ({$noKartuBpjs}) + Tanggal Berobat ({$tglBerobatDb}) duplikat dalam file ini.";
                }

                if (!$isDuplicate) {
                    $existsInStagingBpjs = ResepImport::where('no_kartu_bpjs', $noKartuBpjs)
                        ->whereDate('tanggal_berobat', $tglBerobatDb)
                        ->where('status_import', 'baru')
                        ->where('id', '!=', $resepImport->id)
                        ->exists();
                    if ($existsInStagingBpjs) {
                        $isDuplicate = true;
                        $duplicateReason = "Pelayanan BPJS ({$noKartuBpjs}) tanggal {$tglBerobatDb} sudah pernah di-import.";
                    }
                }

                if (!$isDuplicate) {
                    $existingPasienBpjs = null;
                    if (!empty($row['kode_pasien'])) {
                        $existingPasienBpjs = PasienPrb::where('kode_pasien', $row['kode_pasien'])->first();
                    }
                    if (!$existingPasienBpjs && !empty($noKartuBpjs)) {
                        $existingPasienBpjs = PasienPrb::where('no_kartu_bpjs', $noKartuBpjs)->first();
                    }

                    if ($existingPasienBpjs) {
                        $existsInRiwayatBpjs = RiwayatPengambilanObat::where('pasien_prb_id', $existingPasienBpjs->id)
                            ->where(function ($q) use ($tglBerobatDb) {
                                $q->whereDate('tanggal_berobat', $tglBerobatDb)
                                  ->orWhereDate('tanggal_pengambilan', $tglBerobatDb);
                            })
                            ->exists();
                        if ($existsInRiwayatBpjs) {
                            $isDuplicate = true;
                            $duplicateReason = "Riwayat obat pasien tersebut pada tanggal {$tglBerobatDb} sudah tersimpan.";
                        }
                    }
                }
            }

            if ($isDuplicate) {
                $resepImport->update([
                    'status_import' => 'duplikat',
                    'keterangan_import' => $duplicateReason,
                ]);
                $jumlahDuplikat++;
                continue;
            }

            // Tandai untuk pengecekan intra-batch selanjutnya
            if (!empty($noFaktur)) {
                $processedFakturs[] = $noFaktur;
            }
            if (!empty($noKartuBpjs) && !empty($tglBerobatDb)) {
                $processedBpjsDates[] = $noKartuBpjs . '_' . $tglBerobatDb;
            }

            // 3. Masukkan ke Tabel Utama (pasien_prb & riwayat_pengambilan_obat)
            $resepImport->update([
                'status_import' => 'baru',
                'keterangan_import' => 'Berhasil ditambahkan ke tabel utama',
            ]);
            $jumlahBaru++;

            $pasien = null;
            if (!empty($row['kode_pasien'])) {
                $pasien = PasienPrb::where('kode_pasien', $row['kode_pasien'])->first();
            }
            if (!$pasien && !empty($noKartuBpjs)) {
                $pasien = PasienPrb::where('no_kartu_bpjs', $noKartuBpjs)->first();
            }

            if (!$pasien) {
                $dataPasien = [
                    'kode_pasien' => !empty($row['kode_pasien']) ? $row['kode_pasien'] : PasienPrb::generateKodePasien(),
                    'nama_pasien' => $namaPasien,
                    'no_kartu_bpjs' => $noKartuBpjs,
                    'no_whatsapp' => !empty($row['no_whatsapp']) ? $row['no_whatsapp'] : null,
                    'fktp_asal' => $fktpAsal,
                    'diagnosis_prb' => $diagnosisPrb,
                    'tanggal_mulai_prb' => $tglMulaiDb,
                    'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                    'status' => 'Aktif',
                    'alasan_pasif' => null,
                    'sumber_data' => 'Import Excel',
                    'tanggal_realisasi' => null,
                ];

                $this->setJenisDanJadwal($dataPasien);
                $pasien = PasienPrb::create($dataPasien);
            } else {
                // Update WhatsApp if it's empty and excel has it
                if (empty($pasien->no_whatsapp) && !empty($row['no_whatsapp'])) {
                    $pasien->update([
                        'no_whatsapp' => $row['no_whatsapp']
                    ]);
                }

                // Data lama pasien TIDAK ditimpa profil dasarnya
                // Hanya update tanggal_pengambilan_terakhir dan jadwal jika transaksi ini lebih baru
                $existingTglTerakhir = $pasien->tanggal_pengambilan_terakhir ? $pasien->tanggal_pengambilan_terakhir->toDateString() : null;

                if (empty($existingTglTerakhir) || Carbon::parse($tglAmbilDb)->gte(Carbon::parse($existingTglTerakhir))) {
                    $tempData = [
                        'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                        'tanggal_mulai_prb' => $pasien->tanggal_mulai_prb ? $pasien->tanggal_mulai_prb->toDateString() : $tglMulaiDb,
                    ];
                    $this->setJenisDanJadwal($tempData);

                    $pasien->update([
                        'tanggal_pengambilan_terakhir' => $tglAmbilDb,
                        'jenis_prb' => $tempData['jenis_prb'] ?? $pasien->jenis_prb,
                        'jatah_obat' => $tempData['jatah_obat'] ?? $pasien->jatah_obat,
                        'tanggal_jadwal_berikutnya' => $tempData['tanggal_jadwal_berikutnya'] ?? $pasien->tanggal_jadwal_berikutnya,
                    ]);
                }
            }

            // Record riwayat transaksi pengambilan obat
            $jadwalTarget = $pasien->tanggal_jadwal_berikutnya ? $pasien->tanggal_jadwal_berikutnya->toDateString() : $tglAmbilDb;
            $selisihHari = Carbon::parse($tglAmbilDb)->diffInDays(Carbon::parse($jadwalTarget), false);

            RiwayatPengambilanObat::create([
                'pasien_prb_id' => $pasien->id,
                'no_faktur' => $noFaktur,
                'tanggal_berobat' => $tglBerobatDb,
                'tanggal_pengambilan' => $tglAmbilDb,
                'jadwal_target' => $jadwalTarget,
                'selisih_hari' => (int)$selisihHari,
                'status_kepatuhan' => $selisihHari >= 0 ? 'Patuh' : 'Terlambat',
            ]);
        }

        return redirect()
            ->route('pasien.index')
            ->with('importResult', [
                'baru' => $jumlahBaru,
                'duplikat' => $jumlahDuplikat,
                'invalid' => $jumlahInvalid,
            ])
            ->with(
                'success',
                "Import selesai: {$jumlahBaru} data baru ditambahkan, {$jumlahDuplikat} duplikat dilewati, {$jumlahInvalid} data invalid/gagal."
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

        $query = PasienPrb::orderBy('kode_pasien', 'asc');

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

        $data = $query
            ->get()
            ->map(
                function ($pasien) use (
                    $tanggalEvaluasi
                ) {
                    $kepatuhan =
                        $this->kepatuhanService
                            ->tentukanStatusKepatuhan(
                                $pasien,
                                $tanggalEvaluasi
                            );

                    $lastTindakLanjut =
                        $pasien
                            ->riwayatTindakLanjut()
                            ->latest(
                                'tanggal_jam'
                            )
                            ->first();

                    $tglAmbil = $pasien->tanggal_realisasi 
                        ? $pasien->tanggal_realisasi->format('d/m/Y') 
                        : 'Belum diambil';

                    $jadwalAmbil = $pasien->tanggal_jadwal_berikutnya 
                        ? $pasien->tanggal_jadwal_berikutnya->format('d/m/Y') 
                        : '-';

                    $tindakLanjut = $lastTindakLanjut
                        ? "{$lastTindakLanjut->tindakan} - {$lastTindakLanjut->catatan}"
                        : '-';

                    return [
                        'Kode Pasien' => $pasien->kode_pasien,
                        'Nama Pasien' => $pasien->nama_pasien,
                        'FKTP' => $pasien->fktp_asal,
                        'Diagnosis' => is_array($pasien->diagnosis_prb) ? implode(', ', $pasien->diagnosis_prb) : $pasien->diagnosis_prb,
                        'Jadwal Ambil' => $jadwalAmbil,
                        'Tgl Ambil' => $tglAmbil,
                        'Early Warning' => $kepatuhan['early_warning'] ?? '-',
                        'Status' => $kepatuhan['status'],
                        'Keterangan' => $kepatuhan['keterangan'],
                        'Tindak Lanjut' => $tindakLanjut,
                    ];
                }
            );

        if ($filterKepatuhan) {
            $data = $data->filter(fn($p) => $p['Status'] === $filterKepatuhan)->values();
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
     * ATURAN UTAMA:
     *
     * 1. PRB BARU
     *    Belum pernah mengambil obat.
     *
     *    Jatah = 23 hari
     *    Jadwal = Mulai PRB + 23 hari
     *
     * 2. PRB LAMA
     *    Sudah pernah mengambil obat.
     *
     *    Jatah = 30 hari
     *    Jadwal = Pengambilan Terakhir + 30 hari
     */
    private function setJenisDanJadwal(
        array &$data
    ): void {
        $tanggalTerakhir =
            $data[
                'tanggal_pengambilan_terakhir'
            ] ?? null;

        /*
         * =====================================================
         * PRB BARU
         * =====================================================
         */
        if (
            empty($tanggalTerakhir)
        ) {
            $data['jenis_prb'] =
                'Baru';

            $data['jatah_obat'] =
                23;

            $data['tanggal_jadwal_berikutnya'] =
                Carbon::parse(
                    $data['tanggal_mulai_prb']
                )
                    ->addDays(23)
                    ->toDateString();

            return;
        }

        /*
         * =====================================================
         * PRB LAMA
         * =====================================================
         */
        $data['jenis_prb'] =
            'Lama';

        $data['jatah_obat'] =
            30;

        $data['tanggal_jadwal_berikutnya'] =
            Carbon::parse(
                $tanggalTerakhir
            )
                ->addDays(30)
                ->toDateString();
    }
}