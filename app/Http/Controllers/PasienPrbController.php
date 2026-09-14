<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Models\RiwayatTindakLanjut;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PasienPrbController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    /**
     * Tampilkan daftar semua pasien.
     */
    public function index(Request $request)
    {
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', now()->toDateString());
        $search = $request->input('search', '');
        $filterStatus = $request->input('filter_status', '');
        $filterKepatuhan = $request->input('filter_kepatuhan', '');
        $filterDiagnosis = $request->input('filter_diagnosis', '');

        $query = PasienPrb::query()->orderBy('kode_pasien', 'asc');

        // Pencarian
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_pasien', 'ilike', "%{$search}%")
                  ->orWhere('kode_pasien', 'ilike', "%{$search}%")
                  ->orWhere('no_kartu_bpjs', 'ilike', "%{$search}%");
            });
        }

        // Filter status aktif/pasif
        if ($filterStatus) {
            $query->where('status', $filterStatus);
        }

        // Filter diagnosis
        if ($filterDiagnosis) {
            $query->where('diagnosis_prb', $filterDiagnosis);
        }

        $pasienAll = $query->get()->map(function ($pasien) use ($tanggalEvaluasi) {
            $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
            return array_merge($pasien->toArray(), [
                'status_kepatuhan' => $kepatuhan['status'],
                'selisih_hari_evaluasi' => $kepatuhan['selisih_hari'],
                'keterangan_kepatuhan' => $kepatuhan['keterangan'],
            ]);
        });

        // Filter kepatuhan (setelah kalkulasi)
        if ($filterKepatuhan) {
            $pasienAll = $pasienAll->filter(function ($p) use ($filterKepatuhan) {
                return $p['status_kepatuhan'] === $filterKepatuhan;
            })->values();
        }

        return Inertia::render('Pasien/Index', [
            'pasienList' => $pasienAll,
            'tanggalEvaluasi' => $tanggalEvaluasi,
            'filters' => [
                'search' => $search,
                'filter_status' => $filterStatus,
                'filter_kepatuhan' => $filterKepatuhan,
                'filter_diagnosis' => $filterDiagnosis,
            ],
            'diagnosisOptions' => PasienPrb::DIAGNOSIS_OPTIONS,
            'alasanPasifOptions' => PasienPrb::ALASAN_PASIF_OPTIONS,
        ]);
    }

    /**
     * Simpan pasien baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_pasien' => 'required|string|max:255',
            'no_kartu_bpjs' => 'required|string|max:20',
            'no_whatsapp' => 'nullable|string|max:20',
            'fktp_asal' => 'required|string|max:255',
            'diagnosis_prb' => 'required|string',
            'tanggal_mulai_prb' => 'required|date',
            'tanggal_pengambilan_terakhir' => 'nullable|date',
            'status' => 'required|in:Aktif,Pasif',
            'alasan_pasif' => 'nullable|string',
            'sumber_data' => 'nullable|string',
        ]);

        $validated['kode_pasien'] = PasienPrb::generateKodePasien();
        
        // Logika Pasien Baru vs Lama
        if (empty($validated['tanggal_pengambilan_terakhir'])) {
            $validated['jenis_prb'] = 'Baru';
            $validated['jatah_obat'] = 23;
        } else {
            $validated['jenis_prb'] = 'Lama';
            $validated['jatah_obat'] = 30;
            
            $validated['tanggal_jadwal_berikutnya'] = $this->kepatuhanService
                ->hitungJadwalBerikutnya(
                    $validated['tanggal_pengambilan_terakhir'],
                    $validated['jatah_obat']
                )
                ->toDateString();
        }

        PasienPrb::create($validated);

        return redirect()->route('pasien.index')
            ->with('success', 'Pasien berhasil ditambahkan.');
    }

    /**
     * Tampilkan detail pasien.
     */
    public function show(Request $request, PasienPrb $pasien)
    {
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', now()->toDateString());

        $pasien->load([
            'riwayatPengambilanObat' => fn($q) => $q->orderBy('tanggal_pengambilan', 'desc'),
            'riwayatTindakLanjut' => fn($q) => $q->orderBy('tanggal_jam', 'desc'),
        ]);

        $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
        $data = array_merge($pasien->toArray(), [
            'status_kepatuhan' => $kepatuhan['status'],
            'selisih_hari_evaluasi' => $kepatuhan['selisih_hari'],
            'keterangan_kepatuhan' => $kepatuhan['keterangan'],
        ]);

        return response()->json($data);
    }

    /**
     * Update data pasien.
     */
    public function update(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'nama_pasien' => 'required|string|max:255',
            'no_kartu_bpjs' => 'required|string|max:20',
            'no_whatsapp' => 'nullable|string|max:20',
            'fktp_asal' => 'required|string|max:255',
            'diagnosis_prb' => 'required|string',
            'tanggal_mulai_prb' => 'required|date',
            'jatah_obat' => 'required|integer|min:1|max:90',
            'status' => 'required|in:Aktif,Pasif',
            'alasan_pasif' => 'nullable|string',
        ]);

        $pasien->update($validated);

        return redirect()->back()->with('success', 'Data pasien berhasil diperbarui.');
    }

    /**
     * Hapus pasien.
     */
    public function destroy(PasienPrb $pasien)
    {
        $pasien->delete();
        return redirect()->route('pasien.index')
            ->with('success', 'Pasien berhasil dihapus.');
    }

    /**
     * Quick update tanggal realisasi pengambilan obat.
     */
    public function updateRealisasi(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'tanggal_realisasi' => 'required|date',
        ]);

        $this->kepatuhanService->prosesRealisasi($pasien, $validated['tanggal_realisasi']);

        return redirect()->back()->with('success', 'Realisasi pengambilan obat berhasil dicatat.');
    }

    /**
     * Update status aktif/pasif pasien.
     */
    public function updateStatus(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'status' => 'required|in:Aktif,Pasif',
            'alasan_pasif' => 'nullable|required_if:status,Pasif|string',
        ]);

        $pasien->update($validated);

        return redirect()->back()->with('success', 'Status pasien berhasil diperbarui.');
    }

    /**
     * Import data pasien dari Excel.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.nama_pasien' => 'required|string',
            'data.*.no_kartu_bpjs' => 'required|string',
            'data.*.diagnosis_prb' => 'required|string',
            'data.*.fktp_asal' => 'required|string',
            'data.*.tanggal_mulai_prb' => 'required|date',
            'mode' => 'required|in:update,skip',
        ]);

        $mode = $validated['mode'];
        $imported = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($validated['data'] as $row) {
            $existing = null;
            if (!empty($row['kode_pasien'])) {
                $existing = PasienPrb::where('kode_pasien', $row['kode_pasien'])->first();
            }

            if ($existing) {
                if ($mode === 'update') {
                    $existing->update(array_filter($row));
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $row['kode_pasien'] = $row['kode_pasien'] ?? PasienPrb::generateKodePasien();
            $row['status'] = $row['status'] ?? 'Aktif';
            $row['sumber_data'] = 'Import Excel';

            if (empty($row['tanggal_pengambilan_terakhir'])) {
                $row['jenis_prb'] = 'Baru';
                $row['jatah_obat'] = 23;
            } else {
                $row['jenis_prb'] = 'Lama';
                $row['jatah_obat'] = 30;
                $row['tanggal_jadwal_berikutnya'] = $this->kepatuhanService
                    ->hitungJadwalBerikutnya(
                        $row['tanggal_pengambilan_terakhir'],
                        $row['jatah_obat']
                    )
                    ->toDateString();
            }

            PasienPrb::create($row);
            $imported++;
        }

        return redirect()->route('pasien.index')
            ->with('success', "Import selesai: {$imported} ditambahkan, {$updated} diperbarui, {$skipped} dilewati.");
    }

    /**
     * Export data pasien ke JSON (untuk SheetJS client-side generation).
     */
    public function export(Request $request)
    {
        $tanggalEvaluasi = $request->input('tanggal_evaluasi', now()->toDateString());

        $data = PasienPrb::orderBy('kode_pasien')
            ->get()
            ->map(function ($pasien) use ($tanggalEvaluasi) {
                $kepatuhan = $this->kepatuhanService->tentukanStatusKepatuhan($pasien, $tanggalEvaluasi);
                $lastTindakLanjut = $pasien->riwayatTindakLanjut()->latest('tanggal_jam')->first();

                return [
                    'Kode Pasien' => $pasien->kode_pasien,
                    'Nama Pasien' => $pasien->nama_pasien,
                    'No. Kartu BPJS' => $pasien->no_kartu_bpjs,
                    'No. WhatsApp' => $pasien->no_whatsapp,
                    'FKTP Asal' => $pasien->fktp_asal,
                    'Diagnosis PRB' => $pasien->diagnosis_prb,
                    'Jenis PRB' => $pasien->jenis_prb,
                    'Tanggal Mulai PRB' => $pasien->tanggal_mulai_prb?->format('Y-m-d'),
                    'Pengambilan Terakhir' => $pasien->tanggal_pengambilan_terakhir?->format('Y-m-d'),
                    'Jadwal Berikutnya' => $pasien->tanggal_jadwal_berikutnya?->format('Y-m-d'),
                    'Realisasi' => $pasien->tanggal_realisasi?->format('Y-m-d'),
                    'Jatah Obat (Hari)' => $pasien->jatah_obat,
                    'Status' => $pasien->status,
                    'Status Kepatuhan' => $kepatuhan['status'],
                    'Selisih Hari' => $kepatuhan['selisih_hari'],
                    'Keterangan' => $kepatuhan['keterangan'],
                    'Catatan Tindak Lanjut' => $lastTindakLanjut
                        ? "{$lastTindakLanjut->tindakan} - {$lastTindakLanjut->catatan}"
                        : '',
                    'Tanggal Evaluasi' => $tanggalEvaluasi,
                ];
            });

        return response()->json($data);
    }
}
