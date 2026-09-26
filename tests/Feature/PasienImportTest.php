<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasienImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_flow_staging_and_duplicate_checking(): void
    {
        $payload = [
            'data' => [
                // 1. Valid New Transaction
                [
                    'no_faktur' => 'INV-AUG-001',
                    'nama_pasien' => 'Pasien Bulan Agustus',
                    'no_kartu_bpjs' => '1234567890123',
                    'no_whatsapp' => '081234567890',
                    'fktp_asal' => 'Puskesmas A',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-08-05',
                    'tanggal_pengambilan_terakhir' => '2024-08-05',
                ],
                // 2. Duplicate Transaction in Same Batch (Same No. Faktur)
                [
                    'no_faktur' => 'INV-AUG-001',
                    'nama_pasien' => 'Pasien Bulan Agustus',
                    'no_kartu_bpjs' => '1234567890123',
                    'fktp_asal' => 'Puskesmas A',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-08-05',
                ],
                // 3. Invalid Row (Missing Name & Invalid BPJS)
                [
                    'no_faktur' => 'INV-AUG-003',
                    'nama_pasien' => '',
                    'no_kartu_bpjs' => '123',
                    'fktp_asal' => 'Puskesmas B',
                    'diagnosis_prb' => ['Diabetes Melitus'],
                    'tanggal_mulai_prb' => '2024-08-01',
                ]
            ]
        ];

        $response = $this->post(route('pasien.import'), $payload);

        $response->assertRedirect(route('pasien.index'));
        $response->assertSessionHas('importResult', [
            'baru' => 1,
            'duplikat' => 1,
            'invalid' => 1,
        ]);

        // Check Staging table
        $this->assertDatabaseCount('resep_import', 3);
        $this->assertDatabaseHas('resep_import', [
            'no_faktur' => 'INV-AUG-001',
            'status_import' => 'baru',
        ]);
        $this->assertDatabaseHas('resep_import', [
            'no_faktur' => 'INV-AUG-001',
            'status_import' => 'duplikat',
        ]);
        $this->assertDatabaseHas('resep_import', [
            'no_faktur' => 'INV-AUG-003',
            'status_import' => 'invalid',
        ]);

        // Check Main tables
        $this->assertDatabaseCount('pasien_prb', 1);
        $this->assertDatabaseHas('pasien_prb', [
            'no_kartu_bpjs' => '1234567890123',
            'nama_pasien' => 'Pasien Bulan Agustus',
        ]);
        $this->assertDatabaseCount('riwayat_pengambilan_obat', 1);
        $this->assertDatabaseHas('riwayat_pengambilan_obat', [
            'no_faktur' => 'INV-AUG-001',
        ]);
    }

    public function test_import_next_month_does_not_overwrite_old_data(): void
    {
        // Import Month 1 (August)
        $this->post(route('pasien.import'), [
            'data' => [
                [
                    'no_faktur' => 'INV-AUG-100',
                    'nama_pasien' => 'Budi Santoso',
                    'no_kartu_bpjs' => '9999999999999',
                    'fktp_asal' => 'Klinik Sehat',
                    'diagnosis_prb' => ['Diabetes Melitus'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-08-10',
                    'tanggal_pengambilan_terakhir' => '2024-08-10',
                ]
            ]
        ]);

        // Import Month 2 (September) for same patient
        $response = $this->post(route('pasien.import'), [
            'data' => [
                [
                    'no_faktur' => 'INV-SEP-200',
                    'nama_pasien' => 'Budi Santoso (Updated Name Should Not Overwrite)',
                    'no_kartu_bpjs' => '9999999999999',
                    'fktp_asal' => 'Klinik Sehat Baru',
                    'diagnosis_prb' => ['Diabetes Melitus'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-09-10',
                    'tanggal_pengambilan_terakhir' => '2024-09-10',
                ]
            ]
        ]);

        $response->assertSessionHas('importResult', [
            'baru' => 1,
            'duplikat' => 0,
            'invalid' => 0,
        ]);

        // Patient table should still have 1 record, with original profile intact (not overwritten)
        $this->assertDatabaseCount('pasien_prb', 1);
        $pasien = PasienPrb::where('no_kartu_bpjs', '9999999999999')->first();
        $this->assertEquals('Budi Santoso', $pasien->nama_pasien);
        $this->assertEquals('Klinik Sehat', $pasien->fktp_asal);
        $this->assertEquals('2024-09-10', $pasien->tanggal_pengambilan_terakhir->toDateString());

        // History table should now have 2 records (August & September)
        $this->assertDatabaseCount('riwayat_pengambilan_obat', 2);
        $this->assertDatabaseHas('riwayat_pengambilan_obat', ['no_faktur' => 'INV-AUG-100']);
        $this->assertDatabaseHas('riwayat_pengambilan_obat', ['no_faktur' => 'INV-SEP-200']);
    }

    public function test_bu_lente_monitoring_scenario(): void
    {
        // 1. Transaction 1: Pasien PRB-001 takes medicine on 24/09/2026
        $this->post(route('pasien.import'), [
            'data' => [
                [
                    'no_faktur' => 'TRX-20260924-001',
                    'kode_pasien' => 'PRB-001',
                    'nama_pasien' => 'Siti Lente',
                    'no_kartu_bpjs' => '8888888888888',
                    'no_whatsapp' => '081299998888',
                    'fktp_asal' => 'Puskesmas Bu Lente',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2026-09-01',
                    'tanggal_berobat' => '2026-09-24',
                    'tanggal_pengambilan' => '2026-09-24',
                ]
            ]
        ]);

        // Verify history stored
        $pasien = PasienPrb::where('kode_pasien', 'PRB-001')->first();
        $this->assertNotNull($pasien);
        $this->assertEquals(1, $pasien->riwayatPengambilanObat()->count());
        $this->assertEquals('2026-09-24', $pasien->riwayatPengambilanObat->first()->tanggal_pengambilan->toDateString());

        // 2. Transaction 2: Same patient PRB-001 takes medicine on 24/10/2026
        $this->post(route('pasien.import'), [
            'data' => [
                [
                    'no_faktur' => 'TRX-20261024-001',
                    'kode_pasien' => 'PRB-001',
                    'nama_pasien' => 'Siti Lente',
                    'no_kartu_bpjs' => '8888888888888',
                    'no_whatsapp' => '081299998888',
                    'fktp_asal' => 'Puskesmas Bu Lente',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2026-09-01',
                    'tanggal_berobat' => '2026-10-24',
                    'tanggal_pengambilan' => '2026-10-24',
                ]
            ]
        ]);

        // 3. Verify main table: Pasien PRB table still has exactly ONE row for PRB-001
        $this->assertDatabaseCount('pasien_prb', 1);
        $pasienFresh = PasienPrb::where('kode_pasien', 'PRB-001')->first();
        $this->assertEquals('2026-10-24', $pasienFresh->tanggal_pengambilan_terakhir->toDateString());

        // 4. Verify detail history: Contains BOTH 24/09/2026 and 24/10/2026 records
        $responseShow = $this->getJson(route('pasien.show', $pasienFresh->id));
        $responseShow->assertStatus(200);

        $riwayatList = $responseShow->json('riwayat_pengambilan_obat');
        $this->assertCount(2, $riwayatList);

        $dates = array_map(fn($d) => substr($d, 0, 10), array_column($riwayatList, 'tanggal_pengambilan'));
        $this->assertContains('2026-09-24', $dates);
        $this->assertContains('2026-10-24', $dates);
    }
}
