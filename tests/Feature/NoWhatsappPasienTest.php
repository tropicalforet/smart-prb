<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No. WhatsApp opsional untuk pengingat ambil obat:
 * boleh kosong, dirapikan ke 628xxxxxxxxxx, dan bisa diubah/dihapus.
 */
class NoWhatsappPasienTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dataPasien(array $tambahan = []): array
    {
        return array_merge([
            'noka'              => 'WA 001',
            'fktp_asal'         => 'Puskesmas A',
            'diagnosis_prb'     => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-01-01',
            'status'            => 'Aktif',
        ], $tambahan);
    }

    public function test_tambah_pasien_tanpa_no_whatsapp(): void
    {
        $this->post(route('pasien.store'), $this->dataPasien())->assertSessionHasNoErrors();
        $this->post(route('pasien.store'), $this->dataPasien(['noka' => 'WA 002', 'no_whatsapp' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PasienPrb::whereNotNull('no_whatsapp')->count());
    }

    public function test_no_whatsapp_dirapikan_ke_format_62(): void
    {
        $this->post(route('pasien.store'), $this->dataPasien(['no_whatsapp' => '0812-3456-7890']))
            ->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', PasienPrb::where('noka', 'WA 001')->value('no_whatsapp'));

        foreach (['+62 812 3456 7890', '812 3456 7890', '6281234567890'] as $nomor) {
            $this->assertSame('6281234567890', PasienPrb::normalisasiNoWhatsapp($nomor));
        }
        $this->assertNull(PasienPrb::normalisasiNoWhatsapp('  '));
    }

    public function test_no_whatsapp_tidak_valid_ditolak(): void
    {
        foreach (['12345', '021-555-1234', '0812'] as $nomor) {
            $this->post(route('pasien.store'), $this->dataPasien(['no_whatsapp' => $nomor]))
                ->assertSessionHasErrors('no_whatsapp');
        }

        $this->assertSame(0, PasienPrb::count());
    }

    public function test_ubah_dan_hapus_no_whatsapp(): void
    {
        $this->post(route('pasien.store'), $this->dataPasien());
        $pasien = PasienPrb::where('noka', 'WA 001')->firstOrFail();

        $this->put(route('pasien.update', $pasien), $this->dataPasien(['no_whatsapp' => '0857 1111 2222']))
            ->assertSessionHasNoErrors();
        $this->assertSame('6285711112222', $pasien->fresh()->no_whatsapp);

        // Ubah data lain tanpa mengirim nomor → nomor tetap
        $this->put(route('pasien.update', $pasien), $this->dataPasien(['fktp_asal' => 'Puskesmas B']))
            ->assertSessionHasNoErrors();
        $this->assertSame('6285711112222', $pasien->fresh()->no_whatsapp);

        // Dikosongkan → nomor dihapus
        $this->put(route('pasien.update', $pasien), $this->dataPasien(['no_whatsapp' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($pasien->fresh()->no_whatsapp);
    }

    public function test_detail_pasien_mengirim_no_whatsapp(): void
    {
        $this->post(route('pasien.store'), $this->dataPasien(['no_whatsapp' => '081234567890']));
        $pasien = PasienPrb::where('noka', 'WA 001')->firstOrFail();

        $this->getJson(route('pasien.show', $pasien))
            ->assertOk()
            ->assertJsonPath('no_whatsapp', '6281234567890');
    }
}
