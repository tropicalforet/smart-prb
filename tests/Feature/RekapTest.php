<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RekapTest extends TestCase
{
    use RefreshDatabase;

    private function pasien(int $i, string $fktp, string $jadwal): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien'               => sprintf('PRB-%03d', $i),
            'noka'                      => "PRB UJI {$i}",
            'fktp_asal'                 => $fktp,
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
            'tanggal_jadwal_berikutnya' => $jadwal,
            'status'                    => 'Aktif',
        ]);
    }

    public function test_rekap_menampilkan_persentase_kepatuhan_per_fktp_dan_total(): void
    {
        Carbon::setTestNow('2026-10-20');
        $service = app(KepatuhanService::class);

        // FKTP A: 2 patuh (ambil tepat jadwal) + 1 tidak patuh (overdue H+10) + 1 dalam toleransi (H+2)
        $service->prosesRealisasi($this->pasien(1, 'FKTP A', '2026-10-05'), '2026-10-05');
        $service->prosesRealisasi($this->pasien(2, 'FKTP A', '2026-10-06'), '2026-10-07');
        $this->pasien(3, 'FKTP A', '2026-10-10');
        $this->pasien(4, 'FKTP A', '2026-10-18');
        // FKTP B: hanya pasien yang belum jatuh tempo → belum ada yang dinilai
        $this->pasien(5, 'FKTP B', '2026-11-30');

        $this->actingAs(User::factory()->create())
            ->get('/rekap')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Rekap/Index')
                ->where('summary.patuh', 2)
                ->where('summary.tidak_patuh', 1)
                ->where('summary.dalam_toleransi', 1)
                ->where('summary.persentase_patuh', 66.7)
                ->where('rekapFktp', fn ($rows) => collect($rows)->firstWhere('fktp', 'FKTP A')['persentase_patuh'] === 66.7
                    && collect($rows)->firstWhere('fktp', 'FKTP B')['persentase_patuh'] === null)
            );

        Carbon::setTestNow();
    }
}
