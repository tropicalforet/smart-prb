<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Urutan tabel Data Pasien mengikuti kolom tabel; kode pasien diurutkan
 * natural dan nilai kosong selalu di bawah.
 */
class PasienSortTest extends TestCase
{
    use RefreshDatabase;

    private function pasien(string $kode, ?string $mulai): void
    {
        PasienPrb::create([
            'kode_pasien'       => $kode,
            'noka'              => 'NOKA ' . $kode,
            'fktp_asal'         => 'Puskesmas A',
            'diagnosis_prb'     => ['Hipertensi'],
            'tanggal_mulai_prb' => $mulai,
            'status'            => 'Aktif',
        ]);
    }

    /** @return string[] */
    private function urutanKode(string $query): array
    {
        $kode = [];
        $this->actingAs(User::factory()->create())
            ->get('/pasien?' . $query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$kode) {
                $kode = collect($page->toArray()['props']['pasienList'])->pluck('kode_pasien')->all();
            });

        return $kode;
    }

    public function test_kode_pasien_naik_secara_natural(): void
    {
        $this->pasien('PRB-1000', '2026-01-01');
        $this->pasien('PRB-999', '2026-02-01');
        $this->pasien('PRB-2', '2026-03-01');

        $this->assertSame(['PRB-2', 'PRB-999', 'PRB-1000'], $this->urutanKode('sort_by=kode_pasien'));
        $this->assertSame(['PRB-1000', 'PRB-999', 'PRB-2'], $this->urutanKode('sort_by=kode_pasien&sort_order=desc'));
    }

    public function test_tanggal_kosong_selalu_di_bawah(): void
    {
        $this->pasien('PRB-1', null);
        $this->pasien('PRB-2', '2026-01-01');
        $this->pasien('PRB-3', '2026-05-01');

        $this->assertSame(['PRB-2', 'PRB-3', 'PRB-1'], $this->urutanKode('sort_by=tanggal_mulai_prb&sort_order=asc'));
        $this->assertSame(['PRB-3', 'PRB-2', 'PRB-1'], $this->urutanKode('sort_by=tanggal_mulai_prb&sort_order=desc'));
    }
}
