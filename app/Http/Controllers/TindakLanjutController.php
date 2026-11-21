<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Services\AuditLogService;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TindakLanjutController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService,
        private AuditLogService  $auditLogService
    ) {}

    /**
     * Catat tindak lanjut. Baris gabungan (beberapa diagnosis dengan tanggal
     * sama) → dicatat untuk semua diagnosisnya, termasuk realisasinya, agar
     * tanggal tetap sama. Pesan dari data yang dibuka.
     */
    public function store(Request $request, PasienPrb $pasien)
    {
        $lain = $pasien->kelompok()->reject(fn (PasienPrb $p) => $p->id === $pasien->id);
        $hasil = $this->storeSatu($request, $pasien);
        foreach ($lain as $p) {
            $this->storeSatu($request, $p);
        }

        return $hasil;
    }

    /** Catat tindak lanjut satu data PRB. */
    private function storeSatu(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'petugas'           => 'nullable|string|max:255',
            'tindakan'          => ['required', Rule::in(RiwayatTindakLanjut::TINDAKAN_OPTIONS)],
            // Kategori hanya untuk pasien yang belum mengambil obat
            'kategori'          => ['nullable', 'required_if:tindakan,Belum mengambil obat', Rule::in(RiwayatTindakLanjut::KATEGORI_OPTIONS)],
            'catatan'           => 'nullable|string',
            'tanggal_realisasi' => 'nullable|required_if:tindakan,Sudah mengambil obat|date|before_or_equal:today',
        ]);

        // Sudah mengambil obat → tidak ada kategori tindak lanjut
        if ($validated['tindakan'] !== 'Belum mengambil obat') {
            $validated['kategori'] = null;
        }

        // Resolusi nama petugas: auth user → input form → fallback
        if (auth()->check() && !empty(auth()->user()->name)) {
            $namaPetugas = auth()->user()->name;
        } elseif (!empty($validated['petugas'])) {
            $namaPetugas = trim($validated['petugas']);
        } else {
            $namaPetugas = 'Petugas Apotek';
        }

        $updateRealisasi   = false;
        $realisasiDuplikat = false;
        $sebelum           = AuditLogService::snapshotPasien($pasien);

        // Proses realisasi jika tindakan "Sudah mengambil obat" dan ada tanggal
        if ($validated['tindakan'] === 'Sudah mengambil obat' && !empty($validated['tanggal_realisasi'])) {
            $hasil = $this->kepatuhanService->prosesRealisasi($pasien, $validated['tanggal_realisasi']);

            if ($hasil['duplikat']) {
                $realisasiDuplikat = true;
            } else {
                $updateRealisasi = true;
            }
        }

        RiwayatTindakLanjut::create([
            'pasien_prb_id'             => $pasien->id,
            'tanggal_jam'               => now(),
            'petugas'                   => $namaPetugas,
            'tindakan'                  => $validated['tindakan'],
            'kategori'                  => $validated['kategori'],
            'catatan'                   => $validated['catatan'] ?? null,
            'update_realisasi_otomatis' => $updateRealisasi,
        ]);

        $label = $pasien->identitas();

        // Audit log realisasi jika berhasil
        if ($updateRealisasi) {
            $this->auditLogService->catatRealisasi(
                $pasien->id, $label, $validated['tanggal_realisasi'],
                AuditLogService::rincianRealisasi($pasien, $sebelum, $validated['tanggal_realisasi'])
            );
        }

        // Audit log tindak lanjut
        $this->auditLogService->catatTindakLanjut(
            $pasien->id, $label, $validated['tindakan'],
            array_values(array_filter([
                ['kolom' => 'Tindakan', 'lama' => null, 'baru' => $validated['tindakan']],
                ['kolom' => 'Kategori', 'lama' => null, 'baru' => $validated['kategori']],
                ['kolom' => 'Catatan',  'lama' => null, 'baru' => $validated['catatan'] ?? null],
                ['kolom' => 'Petugas',  'lama' => null, 'baru' => $namaPetugas],
            ], fn ($p) => $p['baru'] !== null && $p['baru'] !== ''))
        );

        // Kategori "Meninggal" → pasien keluar dari pemantauan dengan status Meninggal
        if ($validated['kategori'] === 'Meninggal' && $pasien->status !== 'Meninggal') {
            $sebelumStatus = AuditLogService::snapshotPasien($pasien->fresh());
            $pasien->update(['status' => 'Meninggal', 'alasan_pasif' => null]);

            $this->auditLogService->ubahStatusPasien(
                $pasien->id, $label, 'Meninggal',
                AuditLogService::selisih($sebelumStatus, AuditLogService::snapshotPasien($pasien->fresh()))
            );

            return redirect()->back()->with('success', 'Tindak lanjut berhasil dicatat. Status pasien diubah menjadi Meninggal.');
        }

        if ($realisasiDuplikat) {
            return redirect()
                ->back()
                ->with('info', 'Tindak lanjut berhasil dicatat. Catatan: realisasi pada tanggal tersebut sudah tercatat sebelumnya.');
        }

        return redirect()->back()->with('success', 'Tindak lanjut berhasil dicatat.');
    }
}
