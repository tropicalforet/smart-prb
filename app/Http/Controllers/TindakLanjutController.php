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
     *
     * Jika tindakan = "Sudah mengambil obat" dan sudah ada realisasi
     * pada tanggal yang sama, tetap catat tindak lanjutnya (log percakapan
     * tetap berguna) tapi beri tahu bahwa realisasi sudah tercatat.
     */
    public function store(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'petugas'           => 'nullable|string|max:255',
            'tindakan'          => 'required|string',
            'catatan'           => 'nullable|string',
            'tanggal_realisasi' => 'nullable|date',
        ]);

        // Gunakan nama petugas dari input, atau default 'Petugas'
        $namaPetugas = !empty($validated['petugas']) ? $validated['petugas'] : 'Petugas';

        $updateRealisasi = false;
        $realisasiDuplikat = false;

        // Proses realisasi jika tindakan "Sudah mengambil obat" dan ada tanggal
        if ($validated['tindakan'] === 'Sudah mengambil obat' && !empty($validated['tanggal_realisasi'])) {
            $hasil = $this->kepatuhanService->prosesRealisasi($pasien, $validated['tanggal_realisasi']);

            if ($hasil['duplikat']) {
                // Realisasi sudah tercatat — jangan majukan siklus lagi,
                // tapi tetap simpan catatan tindak lanjut ini.
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

        if ($realisasiDuplikat) {
            return redirect()
                ->back()
                ->with(
                    'info',
                    'Tindak lanjut berhasil dicatat. Catatan: realisasi pada tanggal tersebut sudah tercatat sebelumnya.'
                );
        }

        return redirect()->back()->with('success', 'Tindak lanjut berhasil dicatat.');
    }
}
