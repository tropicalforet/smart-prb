<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Services\AuditLogService;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;

class TindakLanjutController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService,
        private AuditLogService  $auditLogService
    ) {}

    /**
     * Catat tindak lanjut satu pasien.
     */
    public function store(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'petugas'           => 'nullable|string|max:255',
            'tindakan'          => 'required|string',
            'catatan'           => 'nullable|string',
            'tanggal_realisasi' => 'nullable|date',
        ]);

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
                ['kolom' => 'Catatan',  'lama' => null, 'baru' => $validated['catatan'] ?? null],
                ['kolom' => 'Petugas',  'lama' => null, 'baru' => $namaPetugas],
            ], fn ($p) => $p['baru'] !== null && $p['baru'] !== ''))
        );

        if ($realisasiDuplikat) {
            return redirect()
                ->back()
                ->with('info', 'Tindak lanjut berhasil dicatat. Catatan: realisasi pada tanggal tersebut sudah tercatat sebelumnya.');
        }

        return redirect()->back()->with('success', 'Tindak lanjut berhasil dicatat.');
    }
}
