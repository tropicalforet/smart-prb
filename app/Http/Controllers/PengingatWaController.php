<?php

namespace App\Http\Controllers;

use App\Models\PasienPrb;
use App\Models\RiwayatPengingatWa;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class PengingatWaController extends Controller
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * Catat bahwa pengingat WhatsApp dikirim (dipanggil saat tombol
     * "Kirim Pengingat WA" ditekan). Mengembalikan histori terbaru.
     */
    public function store(Request $request, PasienPrb $pasien)
    {
        $validated = $request->validate([
            'tahap'         => 'nullable|string|max:100',
            'jadwal_target' => 'nullable|date',
        ]);

        $petugas = auth()->user()?->name ?: 'Petugas Apotek';

        RiwayatPengingatWa::create([
            'pasien_prb_id' => $pasien->id,
            'dikirim_pada'  => now(),
            'petugas'       => $petugas,
            'tahap'         => $validated['tahap'] ?? null,
            'jadwal_target' => $validated['jadwal_target'] ?? null,
        ]);

        $this->auditLogService->kirimPengingatWa(
            $pasien->id, $pasien->identitas(),
            array_values(array_filter([
                ['kolom' => 'Tahap',         'lama' => null, 'baru' => $validated['tahap'] ?? null],
                ['kolom' => 'Jadwal ambil',  'lama' => null, 'baru' => $validated['jadwal_target'] ?? null],
                ['kolom' => 'Petugas',       'lama' => null, 'baru' => $petugas],
            ], fn ($p) => $p['baru'] !== null && $p['baru'] !== ''))
        );

        return response()->json(
            $pasien->riwayatPengingatWa()->orderByDesc('dikirim_pada')->get()
        );
    }
}
