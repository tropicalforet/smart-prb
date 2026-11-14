<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_demo_terisi_lewat_proses_import(): void
    {
        User::factory()->create();

        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(50, PasienPrb::distinct()->count('noka'));
        $this->assertSame(0, PasienPrb::where('noka', 'not like', '999%')->count(), 'NOKA demo fiktif');
        $this->assertTrue(PasienPrb::where('status', 'Pasif')->exists());
        $this->assertTrue(PasienPrb::where('status', 'Meninggal')->exists());
        $this->assertTrue(PasienPrb::where('jenis_prb', 'Baru')->exists());
        $this->assertTrue(RiwayatPengambilanObat::where('status_kepatuhan', 'Tidak Teratur')->exists());

        // Satu pasien dua diagnosis → kode sama
        $this->assertTrue(PasienPrb::selectRaw('kode_pasien')->groupBy('kode_pasien')->havingRaw('count(*) > 1')->exists());

        // Nama tersimpan tersamar
        $this->assertStringContainsString('*', PasienPrb::value('nama_samaran'));
    }

    public function test_tidak_ditambahkan_ke_database_yang_sudah_berisi_data(): void
    {
        User::factory()->create();
        PasienPrb::create([
            'kode_pasien' => 'PRB-ASLI', 'noka' => '0001', 'fktp_asal' => 'X',
            'diagnosis_prb' => ['Asma'], 'status' => 'Aktif',
        ]);

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, PasienPrb::count());
    }
}
