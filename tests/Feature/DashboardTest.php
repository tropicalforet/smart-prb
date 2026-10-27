<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function pasien(int $i, string $jadwal): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien'               => sprintf('PRB-%03d', $i),
            'noka'             => str_pad((string) $i, 13, '0', STR_PAD_LEFT),
            'fktp_asal'                 => 'Puskesmas A',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'tanggal_jadwal_berikutnya' => $jadwal,
            'status'                    => 'Aktif',
        ]);
    }

    public function test_tabel_dashboard_hanya_berisi_maks_10_pasien_tidak_patuh_tanpa_riwayat(): void
    {
        Carbon::setTestNow('2026-10-24');

        // 12 pasien overdue (Tidak Patuh) + 3 pasien yang jadwalnya belum tiba
        for ($i = 1; $i <= 12; $i++) {
            $this->pasien($i, Carbon::today()->subDays(10 + $i)->toDateString());
        }
        for ($i = 13; $i <= 15; $i++) {
            $this->pasien($i, Carbon::today()->addDays(20)->toDateString());
        }

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('pasienAktif', 10)
                ->where('pasienAktif.0.status_kepatuhan', 'Tidak Patuh')
                // jadwal paling lama terlewat (paling mendesak) di urutan pertama
                ->where('pasienAktif.0.kode_pasien', 'PRB-012')
                ->missing('pasienAktif.0.riwayat_pengambilan_obat')
                ->where('statistik.total_aktif', 15)
            );

        Carbon::setTestNow();
    }
}
