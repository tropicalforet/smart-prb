<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
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

    public function test_dashboard_tidak_mengirim_daftar_pasien_tidak_patuh(): void
    {
        Carbon::setTestNow('2026-10-24');

        // 12 pasien overdue (Tidak Teratur) + 3 pasien yang jadwalnya belum tiba
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
                // tabel "Pasien Tidak Teratur" sudah dihapus dari dashboard
                ->missing('pasienAktif')
                ->where('statistik.total_aktif', 15)
            );

        Carbon::setTestNow();
    }

    public function test_tren_bulan_sama_dengan_kpi_saat_filter_periode_bulan_itu(): void
    {
        Carbon::setTestNow('2026-10-24');

        // Pasien 1: siklus September patuh, tercatat DUA baris riwayat
        // (mis. dobel import) → tetap dihitung satu siklus.
        $p1 = $this->pasien(1, '2026-10-10');
        foreach (['F-1', 'F-2'] as $faktur) {
            RiwayatPengambilanObat::create([
                'pasien_prb_id'       => $p1->id,
                'no_faktur'           => $faktur,
                'tanggal_pengambilan' => '2026-09-10',
                'jadwal_target'       => '2026-09-10',
                'selisih_hari'        => 0,
                'status_kepatuhan'    => 'Patuh',
            ]);
        }

        // Pasien 2: jadwal September terlewat tanpa riwayat → Tidak Teratur
        $this->pasien(2, '2026-09-05');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('trenKepatuhan', 12)
                ->where('trenKepatuhan.10.periode', '2026-09')
                ->where('trenKepatuhan.10.patuh', 1)
                ->where('trenKepatuhan.10.tidak_patuh', 1)
                ->where('trenKepatuhan.10.persentase', 50)
                // bulan tanpa pasien yang dinilai → null, bukan 0%
                ->where('trenKepatuhan.0.persentase', null)
            );

        $this->actingAs($user)->get('/?filter_periode=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistik.patuh', 1)
                ->where('statistik.tidak_patuh', 1)
            );

        Carbon::setTestNow();
    }
}
