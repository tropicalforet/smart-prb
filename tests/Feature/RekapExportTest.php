<?php

namespace Tests\Feature;

use App\Exports\RekapKepatuhanExport;
use App\Models\PasienPrb;
use App\Models\User;
use App\Services\KepatuhanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RekapExportTest extends TestCase
{
    use RefreshDatabase;

    private function pasienDenganRealisasi(): PasienPrb
    {
        $pasien = PasienPrb::create([
            'kode_pasien'               => 'PRB-001',
            'noka'                      => 'PRB KPMA 001',
            'fktp_asal'                 => 'Puskesmas Padasuka',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-20',
            'status'                    => 'Aktif',
        ]);

        app(KepatuhanService::class)->prosesRealisasi($pasien, '2026-09-21');

        return $pasien;
    }

    public function test_isi_ekspor_rekap_memakai_noka_tanpa_identitas(): void
    {
        $this->pasienDenganRealisasi();

        foreach (['', '2026-09'] as $periode) {
            $export = new RekapKepatuhanExport($periode, null, null, '2026-09-25');
            $rows   = $export->collection();

            $this->assertCount(1, $rows, "periode '{$periode}'");
            $row = $rows->first();

            $this->assertSame('PRB KPMA 001', $row['noka']);
            $this->assertSame('Patuh', $row['status_kepatuhan']);
            $this->assertArrayNotHasKey('nama_pasien', $row);
            $this->assertArrayNotHasKey('no_whatsapp', $row);
            // Jumlah kolom data harus sama dengan jumlah header
            $this->assertCount(count($export->headings()), $row);
        }

        $this->assertNotContains('Nama Pasien', $export->headings());
        $this->assertContains('NOKA', $export->headings());
    }

    public function test_endpoint_ekspor_rekap_berhasil_diunduh(): void
    {
        $this->pasienDenganRealisasi();

        $this->actingAs(User::factory()->create())
            ->get('/rekap/export?filter_periode=2026-09')
            ->assertOk()
            ->assertDownload();
    }
}
