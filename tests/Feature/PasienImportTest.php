<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasienImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Semua rute pasien dilindungi middleware auth
        $this->actingAs(User::factory()->create());
    }

    public function test_import_flow_staging_and_duplicate_checking(): void
    {
        $payload = [
            'data' => [
                // 1. Valid New Transaction
                [
                    'no_faktur' => 'INV-AUG-001',
                    'noka' => '1234567890123',
                    'fktp_asal' => 'Puskesmas A',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-08-05',
                    'tanggal_pengambilan_terakhir' => '2024-08-05',
                ],
                // 2. Duplicate Transaction in Same Batch (Same No. Faktur)
                [
                    'no_faktur' => 'INV-AUG-001',
                    'noka' => '1234567890123',
                    'fktp_asal' => 'Puskesmas A',
                    'diagnosis_prb' => ['Hipertensi'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-08-05',
                ],
                // 3. Invalid Row (NOKA kosong)
                [
                    'no_faktur' => 'INV-AUG-003',
                    'noka' => '',
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
            'diperbarui' => 0,
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
            'noka' => '1234567890123',
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
                    'noka' => '9999999999999',
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
                    'noka' => '9999999999999',
                    'fktp_asal' => 'Klinik Sehat Baru',
                    'diagnosis_prb' => ['Diabetes Melitus'],
                    'tanggal_mulai_prb' => '2024-08-01',
                    'tanggal_berobat' => '2024-09-10',
                    'tanggal_pengambilan_terakhir' => '2024-09-10',
                ]
            ]
        ]);

        $response->assertSessionHas('importResult', [
            'baru' => 0,
            'diperbarui' => 1,
            'duplikat' => 0,
            'invalid' => 0,
        ]);

        // Patient table should still have 1 record, with original profile intact (not overwritten)
        $this->assertDatabaseCount('pasien_prb', 1);
        $pasien = PasienPrb::where('noka', '9999999999999')->first();
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
                    'noka' => '8888888888888',
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
                    'noka' => '8888888888888',
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

    /**
     * Format data apotek (ketentuan etik): NOKA tersandi berformat bebas,
     * tanpa nama pasien & No. HP. Kolom identitas yang tetap terkirim
     * diabaikan dan tidak tersimpan di mana pun.
     */
    public function test_import_format_etik_noka_tersandi_tanpa_nama_dan_no_hp(): void
    {
        $response = $this->post(route('pasien.import'), [
            'data' => [
                [
                    'noka'                         => 'PRB KPMA 001',
                    'fktp_asal'                    => 'Puskesmas Antapani',
                    'diagnosis_prb'                => ['Hipertensi', 'DM'],
                    'tanggal_mulai_prb'            => '2026-01-05',
                    'tanggal_pengambilan_terakhir' => '2026-09-20',
                ],
                [
                    // File lama yang masih membawa kolom identitas
                    'noka'              => 'PRB KPMA 002',
                    'nama_pasien'       => 'Tidak Boleh Tersimpan',
                    'no_whatsapp'       => '081200000000',
                    'fktp_asal'         => 'Puskesmas Antapani',
                    'diagnosis_prb'     => ['Asma'],
                    'tanggal_mulai_prb' => '2026-02-01',
                ],
            ],
        ]);

        $response->assertSessionHas('importResult', [
            'baru' => 2, 'diperbarui' => 0, 'duplikat' => 0, 'invalid' => 0,
        ]);

        $pasien = PasienPrb::where('noka', 'PRB KPMA 001')->firstOrFail();
        $this->assertSame('2026-09-20', $pasien->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame(1, $pasien->riwayatPengambilanObat()->count());

        // Data identitas tidak boleh muncul di tabel mana pun
        foreach (['pasien_prb', 'resep_import'] as $tabel) {
            $this->assertFalse(\Schema::hasColumn($tabel, 'nama_pasien'), "$tabel.nama_pasien");
            $this->assertFalse(\Schema::hasColumn($tabel, 'no_whatsapp'), "$tabel.no_whatsapp");
            $dump = json_encode(\DB::table($tabel)->get());
            $this->assertStringNotContainsString('Tidak Boleh Tersimpan', $dump);
            $this->assertStringNotContainsString('081200000000', $dump);
        }
    }

    public function test_tambah_pasien_manual_dengan_noka_tersandi(): void
    {
        $this->post(route('pasien.store'), [
            'noka'              => 'PRB KPMA 010',
            'fktp_asal'         => 'Klinik Pratama Y',
            'diagnosis_prb'     => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-03-01',
            'status'            => 'Aktif',
        ])->assertSessionHasNoErrors()->assertRedirect(route('pasien.index'));

        $this->assertDatabaseHas('pasien_prb', ['noka' => 'PRB KPMA 010']);
        $this->assertDatabaseHas('audit_logs', ['entitas_label' => 'PRB-001 (NOKA PRB KPMA 010)']);
    }
}
