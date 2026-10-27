<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SitiAminahTest extends TestCase
{
    use RefreshDatabase;

    public function test_siti_aminah()
    {
        $service = new KepatuhanService();
        $pasien = PasienPrb::create([
            'kode_pasien' => 'PRB-SITI',
            'noka' => '0001234567891',
            'fktp_asal' => 'Puskesmas',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-01-01',
            'jenis_prb' => 'Lama',
            'jatah_obat' => 30,
            'tanggal_pengambilan_terakhir' => '2026-08-25',
            'tanggal_jadwal_berikutnya' => '2026-09-24',
            'status' => 'Aktif',
        ]);

        $updated = $service->prosesRealisasi($pasien, '2026-09-24')['pasien'];

        $this->assertEquals('2026-09-24', $updated->tanggal_pengambilan_terakhir->toDateString());
        $this->assertEquals('2026-10-24', $updated->tanggal_jadwal_berikutnya->toDateString());

        $riwayat = $updated->riwayatPengambilanObat()->first();
        $this->assertNotNull($riwayat);
        $this->assertEquals('2026-09-24', $riwayat->jadwal_target->toDateString());
        $this->assertEquals('2026-09-24', $riwayat->tanggal_pengambilan->toDateString());
        $this->assertEquals(0, $riwayat->selisih_hari);
        $this->assertEquals('Patuh', $riwayat->status_kepatuhan);

        // Status = siklus terakhir selesai (Patuh), EWS = siklus aktif berikutnya
        $updated->load('riwayatPengambilanObat'); // pastikan data riwayat ada
        $kepatuhan = $service->tentukanStatusKepatuhan($updated, '2026-09-24');
        $this->assertEquals('Patuh', $kepatuhan['status']);
        $this->assertEquals('Belum memasuki jadwal', $kepatuhan['early_warning']);
    }
}
