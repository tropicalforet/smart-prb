<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hasil Tindakan hanya boleh salah satu dari empat pilihan baku.
 */
class TindakLanjutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pasien(): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien'               => 'PRB-TL01',
            'noka'                      => 'TL 001',
            'fktp_asal'                 => 'Puskesmas A',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'tanggal_jadwal_berikutnya' => '2026-10-25',
            'status'                    => 'Aktif',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
        ]);
    }

    public function test_pilihan_hasil_tindakan_sesuai_ketentuan(): void
    {
        $this->assertSame([
            'Sudah mengambil obat',
            'Belum mengambil obat',
            'Tidak dapat dihubungi',
            'Kendala pengambilan obat',
        ], RiwayatTindakLanjut::TINDAKAN_OPTIONS);
    }

    public function test_pilihan_tanpa_tanggal_tersimpan(): void
    {
        $pasien = $this->pasien();

        foreach (['Belum mengambil obat', 'Tidak dapat dihubungi', 'Kendala pengambilan obat'] as $tindakan) {
            $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => $tindakan])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $pasien->riwayatTindakLanjut()->count());
        $this->assertSame(0, $pasien->riwayatPengambilanObat()->count());
    }

    public function test_sudah_mengambil_obat_mencatat_realisasi(): void
    {
        $pasien = $this->pasien();

        $this->post(route('tindak-lanjut.store', $pasien), [
            'tindakan'          => 'Sudah mengambil obat',
            'tanggal_realisasi' => '2026-10-26',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $pasien->riwayatPengambilanObat()->count());
        $this->assertSame('2026-10-26', $pasien->fresh()->tanggal_pengambilan_terakhir->toDateString());
    }

    public function test_pilihan_lama_atau_tanggal_tidak_valid_ditolak(): void
    {
        $pasien = $this->pasien();

        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Rujuk FKTP'])
            ->assertSessionHasErrors('tindakan');
        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Sudah mengambil obat'])
            ->assertSessionHasErrors('tanggal_realisasi');
        $this->post(route('tindak-lanjut.store', $pasien), [
            'tindakan'          => 'Sudah mengambil obat',
            'tanggal_realisasi' => '2026-10-31',
        ])->assertSessionHasErrors('tanggal_realisasi');

        $this->assertSame(0, $pasien->riwayatTindakLanjut()->count());
    }
}
