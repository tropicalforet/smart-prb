<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Services\KepatuhanService;
use Illuminate\Http\Request;

class TindakLanjutController extends Controller
{
    public function __construct(
        private KepatuhanService $kepatuhanService
    ) {}

    /**
     * Catat tindak lanjut petugas.
     */
    public function store(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'petugas' => 'required|string|max:255',
            'tindakan' => 'required|string',
            'catatan' => 'nullable|string',
            'tanggal_realisasi' => 'nullable|date',
        ]);

        $updateRealisasi = false;

        // Jika tindakan adalah "Sudah mengambil obat" dan ada tanggal realisasi
        if ($validated['tindakan'] === 'Sudah mengambil obat' && !empty($validated['tanggal_realisasi'])) {
            $this->kepatuhanService->prosesRealisasi($pasien, $validated['tanggal_realisasi']);
            $updateRealisasi = true;
        }

        RiwayatTindakLanjut::create([
            'pasien_prb_id' => $pasien->id,
            'tanggal_jam' => now(),
            'petugas' => $validated['petugas'],
            'tindakan' => $validated['tindakan'],
            'catatan' => $validated['catatan'] ?? null,
            'update_realisasi_otomatis' => $updateRealisasi,
        ]);

        return redirect()->back()->with('success', 'Tindak lanjut berhasil dicatat.');
    }
}
