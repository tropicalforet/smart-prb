<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realisasi pengambilan obat pasien PRB Lama dari halaman Pasien:
 * riwayat lama tetap ada, siklus baru dinilai terhadap jadwal aktif
 * (H-3 s.d. H+3 = Patuh), lalu jadwal dimajukan tanggal ambil + 30 hari.
 */
class RealisasiPrbLamaTest extends TestCase
{
    use RefreshDatabase;

    private function pasienKpma(): PasienPrb
    {
        // Mulai 01/01/2025, terakhir ambil 01/01/2026, jadwal berikutnya 01/02/2026
        $pasien = PasienPrb::create([
            'kode_pasien'                  => 'PRB-001',
            'noka'                         => 'PRB KPMA 001',
            'fktp_asal'                    => 'Puskesmas A',
            'diagnosis_prb'                => ['Hipertensi'],
            'tanggal_mulai_prb'            => '2025-01-01',
            'tanggal_pengambilan_terakhir' => '2026-01-01',
            'tanggal_jadwal_berikutnya'    => '2026-02-01',
            'status'                       => 'Aktif',
            'jenis_prb'                    => 'Lama',
            'jatah_obat'                   => 30,
        ]);

        RiwayatPengambilanObat::create([
            'pasien_prb_id'       => $pasien->id,
            'no_faktur'           => 'INV-JAN',
            'tanggal_pengambilan' => '2026-01-01',
            'jadwal_target'       => '2026-01-01',
            'selisih_hari'        => 0,
            'status_kepatuhan'    => 'Patuh',
        ]);

        return $pasien;
    }

    /** @return array<int, array{ambil: string, target: string, selisih: int, status: string}> */
    private function riwayat(PasienPrb $pasien): array
    {
        return $pasien->riwayatPengambilanObat()->orderBy('tanggal_pengambilan')->get()
            ->map(fn ($r) => [
                'ambil'   => $r->tanggal_pengambilan->toDateString(),
                'target'  => $r->jadwal_target->toDateString(),
                'selisih' => $r->selisih_hari,
                'status'  => $r->status_kepatuhan,
            ])->all();
    }

    public function test_ambil_dalam_toleransi_patuh_dan_jadwal_maju_30_hari(): void
    {
        Carbon::setTestNow('2026-02-03');
        $pasien = $this->pasienKpma();

        $this->actingAs(User::factory()->create())
            ->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-02-03'])
            ->assertRedirect();

        $pasien->refresh();
        $this->assertSame('2026-02-03', $pasien->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-03-05', $pasien->tanggal_jadwal_berikutnya->toDateString());
        $this->assertSame('Lama', $pasien->jenis_prb);
        $this->assertSame(30, $pasien->jatah_obat);

        $this->assertSame([
            ['ambil' => '2026-01-01', 'target' => '2026-01-01', 'selisih' => 0, 'status' => 'Patuh'],
            ['ambil' => '2026-02-03', 'target' => '2026-02-01', 'selisih' => 2, 'status' => 'Patuh'],
        ], $this->riwayat($pasien));

        Carbon::setTestNow();
    }

    public function test_ambil_di_luar_toleransi_tidak_patuh(): void
    {
        Carbon::setTestNow('2026-02-08');
        $pasien = $this->pasienKpma();

        // 08/02 = H+7 dari jadwal 01/02 → di luar H+3
        $this->actingAs(User::factory()->create())
            ->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-02-08'])
            ->assertRedirect();

        $pasien->refresh();
        $this->assertSame('2026-02-08', $pasien->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-03-10', $pasien->tanggal_jadwal_berikutnya->toDateString());
        $this->assertSame(
            ['ambil' => '2026-02-08', 'target' => '2026-02-01', 'selisih' => 7, 'status' => 'Tidak Patuh'],
            $this->riwayat($pasien)[1]
        );

        Carbon::setTestNow();
    }
}
