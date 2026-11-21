<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hanya 8 FKTP penelitian, ditampilkan dengan nama lengkap (notulensi 15/11/2026). */
class FktpPenelitianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_bakukan_fktp(): void
    {
        foreach ([
            'BBKN SARI' => 'Puskesmas Babakan Sari', 'B. SBY' => 'Puskesmas Babakan Surabaya',
            'griya antapani' => 'Puskesmas Griya Antapani', 'IBRA' => 'Puskesmas Ibrahim Adjie',
            'PADASUKA' => 'Puskesmas Padasuka', 'ASRI HUSADA 1' => 'Klinik Pratama Asri Husada I',
            'KPMA' => 'Klinik Pratama Medika Antapani', 'SENO' => 'Klinik Pratama Seno Medika',
            'Puskesmas Ibrahim Adjie' => 'Puskesmas Ibrahim Adjie', 'KP Seno Medika' => 'Klinik Pratama Seno Medika',
            'PKM Padasuka' => 'Puskesmas Padasuka',
        ] as $awal => $baku) {
            $this->assertSame($baku, PasienPrb::bakukanFktp($awal), $awal);
        }
        $this->assertNull(PasienPrb::bakukanFktp('Cijagra'));
        $this->assertNull(PasienPrb::bakukanFktp(''));
    }

    public function test_impor_singkatan_jadi_nama_lengkap_dan_fktp_lain_tidak_valid(): void
    {
        $baris = fn ($noka, $fktp) => [
            'noka' => $noka, 'fktp_asal' => $fktp, 'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2025-01-01', 'tanggal_pengambilan' => '2026-03-05',
        ];

        $this->postJson(route('pasien.import'), ['data' => [$baris('N1', 'KPMA'), $baris('N2', 'Cijagra')]])
            ->assertJson(['baru' => 1, 'invalid' => 1]);

        $this->assertSame(['Klinik Pratama Medika Antapani'], PasienPrb::pluck('fktp_asal')->all());
        $this->assertSame('FKTP Cijagra bukan salah satu dari 8 FKTP penelitian',
            ResepImport::where('status_import', 'invalid')->value('keterangan_import'));
    }

    public function test_form_hanya_menerima_8_fktp(): void
    {
        $data = ['noka' => 'N1', 'diagnosis_prb' => ['Asma'], 'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif'];

        $this->post(route('pasien.store'), $data + ['fktp_asal' => 'Cijagra'])->assertSessionHasErrors('fktp_asal');
        $this->post(route('pasien.store'), $data + ['fktp_asal' => 'IBRA'])->assertSessionHasNoErrors();

        $this->assertSame(['Puskesmas Ibrahim Adjie'], PasienPrb::pluck('fktp_asal')->all());
    }

    public function test_perintah_bakukan_fktp_dan_keluarkan_fktp_lain(): void
    {
        $buat = fn ($kode, $fktp) => PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => "N-{$kode}", 'fktp_asal' => $fktp, 'diagnosis_prb' => ['Asma'],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif',
        ]);
        $buat('PRB-1', 'KPMA');
        $buat('PRB-2', 'B SBY');
        $buat('PRB-3', 'Puskesmas Padasuka');
        $cijagra = $buat('PRB-4', 'Cijagra');
        $cijagra->riwayatPengambilanObat()->create([
            'tanggal_pengambilan' => '2026-03-05', 'jadwal_target' => '2026-03-05', 'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh',
        ]);

        $this->artisan('prb:bakukan-fktp --dry-run')->assertSuccessful();
        $this->assertSame(4, PasienPrb::count());

        $this->artisan('prb:bakukan-fktp')->assertSuccessful();
        $this->assertSame(
            ['Klinik Pratama Medika Antapani', 'Puskesmas Babakan Surabaya', 'Puskesmas Padasuka'],
            PasienPrb::orderBy('fktp_asal')->pluck('fktp_asal')->all()
        );
        $this->assertDatabaseMissing('riwayat_pengambilan_obat', ['pasien_prb_id' => $cijagra->id]);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'bakukan_fktp']);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'hapus_massal']);

        // Dijalankan ulang: tidak ada perubahan
        $this->artisan('prb:bakukan-fktp')->assertSuccessful();
        $this->assertSame(3, PasienPrb::count());
    }
}
