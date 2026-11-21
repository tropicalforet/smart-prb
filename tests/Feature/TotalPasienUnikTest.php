<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Daftar pasien tampil per diagnosis, tetapi Total Pasien dihitung per orang
 * (NOKA unik): pasien dengan dua diagnosis dihitung satu kali.
 */
class TotalPasienUnikTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24');
        $this->actingAs(User::factory()->create());

        // Pasien A: dua diagnosis (dua data PRB), Pasien B: satu diagnosis.
        // Semua jadwalnya sudah lewat jauh → Tidak Teratur (sudah dinilai).
        $this->buat('PRB-1', '0001112223334', 'Hipertensi', 'Puskesmas Padasuka');
        $this->buat('PRB-1', '0001112223334', 'Diabetes Melitus', 'Puskesmas Padasuka');
        $this->buat('PRB-2', '0005556667778', 'Asma', 'Puskesmas Griya Antapani');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buat(string $kode, string $noka, string $diagnosis, string $fktp): void
    {
        PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => $noka, 'fktp_asal' => $fktp,
            'diagnosis_prb' => [$diagnosis], 'tanggal_mulai_prb' => '2026-01-01',
            'tanggal_jadwal_berikutnya' => '2026-09-01', 'status' => 'Aktif',
        ]);
    }

    public function test_jumlah_pasien_unik(): void
    {
        $this->assertSame(2, PasienPrb::jumlahPasienUnik(['0001 112', '0001112', ' 0001112 ', 'abc', 'ABC']));
        $this->assertSame(3, PasienPrb::jumlahPasienUnik(['X1', '', null]), 'tanpa NOKA dihitung per data');
        $this->assertSame(2, PasienPrb::jumlahPasienUnik(PasienPrb::all()));
    }

    public function test_dashboard_total_pasien_per_noka(): void
    {
        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('statistik.total_pasien', 2)
            ->where('statistik.total_diagnosis', 3)
            ->where('statistik.tidak_patuh', 3) // kepatuhan tetap per diagnosis
        );
    }

    public function test_rekap_total_pasien_per_noka(): void
    {
        $this->get(route('rekap.index'))->assertInertia(fn (Assert $p) => $p
            ->where('summary.total', 2)
            ->where('summary.total_diagnosis', 3)
            ->where('summary.tidak_patuh', 3)
            ->where('rekapFktp.0.fktp', 'Puskesmas Padasuka')
            ->where('rekapFktp.0.total', 1)
            ->where('rekapFktp.0.total_diagnosis', 2)
            ->where('rekapFktp.0.tidak_patuh', 2)
        );
    }

    public function test_daftar_pasien_tetap_per_diagnosis(): void
    {
        $this->get(route('pasien.index'))->assertInertia(fn (Assert $p) => $p->has('pasienList', 3));
    }
}
