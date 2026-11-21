<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use App\Models\User;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hapus pengambilan obat yang salah input (mis. tindak lanjut diinput dua kali). */
class HapusRealisasiTest extends TestCase
{
    use RefreshDatabase;

    private KepatuhanService $svc;
    private PasienPrb $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10');
        $this->actingAs(User::factory()->create());
        $this->svc = app(KepatuhanService::class);

        // Seperti PRB-0500: ambil 27/03 dan 28/04, lalu realisasi Mei terinput dua kali
        $this->pasien = PasienPrb::create([
            'kode_pasien' => 'PRB-0500', 'noka' => '1281278057', 'fktp_asal' => 'Puskesmas Ibrahim Adjie',
            'diagnosis_prb' => ['Penyakit Jantung'], 'tanggal_mulai_prb' => '2025-01-29', 'status' => 'Aktif',
            'jenis_prb' => 'Lama', 'jatah_obat' => 30, 'tanggal_jadwal_berikutnya' => '2026-03-27',
        ]);
        // Dicatat berurutan (detik berbeda): 24/05 = input ganda yang dicatat terakhir
        foreach (['2026-03-27', '2026-04-28', '2026-05-26', '2026-05-24'] as $i => $t) {
            Carbon::setTestNow("2026-06-10 08:00:0{$i}");
            $this->svc->prosesRealisasi($this->pasien->fresh(), $t);
        }
        Carbon::setTestNow('2026-06-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function riwayat(string $tanggal): RiwayatPengambilanObat
    {
        return $this->pasien->riwayatPengambilanObat()->where('tanggal_pengambilan', 'like', "{$tanggal}%")->firstOrFail();
    }

    private function hapus(string $tanggal)
    {
        return $this->delete(route('pasien.realisasi.hapus', [$this->pasien, $this->riwayat($tanggal)->id]));
    }

    public function test_hapus_pengambilan_ganda_terbaru_lalu_jadwal_dihitung_ulang(): void
    {
        // Input kedua (24/05) tercatat untuk jadwal Juni → Tidak Teratur
        $this->assertSame('Tidak Teratur', $this->riwayat('2026-05-24')->status_kepatuhan);

        $this->hapus('2026-05-24')->assertSessionHas('success');

        $p = $this->pasien->fresh();
        $this->assertSame(3, $p->riwayatPengambilanObat()->count());
        $this->assertSame('2026-05-26', $p->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-06-25', $p->tanggal_jadwal_berikutnya->toDateString(), '26/05 + 30 hari');
        $this->assertSame('Patuh', $this->riwayat('2026-05-26')->status_kepatuhan);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'hapus_realisasi', 'entitas_id' => $p->id]);

        // Berikutnya dihapus berurutan dari yang terakhir dicatat
        $this->hapus('2026-05-26')->assertSessionHas('success');
        $p = $p->fresh();
        $this->assertSame('2026-04-28', $p->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-05-28', $p->tanggal_jadwal_berikutnya->toDateString());
    }

    public function test_hanya_pengambilan_terbaru_yang_bisa_dihapus(): void
    {
        $this->hapus('2026-04-28')->assertSessionHas('info');
        $this->assertSame(4, $this->pasien->riwayatPengambilanObat()->count());
    }

    public function test_pengambilan_dari_impor_tidak_bisa_dihapus(): void
    {
        $this->pasien->riwayatPengambilanObat()->create([
            'no_faktur' => 'F-IMPOR-1', 'tanggal_pengambilan' => '2026-06-01', 'jadwal_target' => '2026-06-23',
            'selisih_hari' => -22, 'status_kepatuhan' => 'Tidak Teratur',
        ]);

        $this->hapus('2026-06-01')->assertSessionHas('info');
        $this->assertSame(5, $this->pasien->riwayatPengambilanObat()->count());
    }

    public function test_hapus_satu_satunya_pengambilan_mengembalikan_jadwal(): void
    {
        $baru = PasienPrb::create([
            'kode_pasien' => 'PRB-9', 'noka' => 'N9', 'fktp_asal' => 'Puskesmas Padasuka', 'diagnosis_prb' => ['Asma'],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif', 'jenis_prb' => 'Lama', 'jatah_obat' => 30,
            'tanggal_jadwal_berikutnya' => '2026-05-10',
        ]);
        $this->svc->prosesRealisasi($baru, '2026-05-09');
        $id = $baru->riwayatPengambilanObat()->value('id');

        $this->delete(route('pasien.realisasi.hapus', [$baru, $id]))->assertSessionHas('success');

        $baru = $baru->fresh();
        $this->assertNull($baru->tanggal_pengambilan_terakhir);
        $this->assertSame('2026-05-10', $baru->tanggal_jadwal_berikutnya->toDateString());
    }

    public function test_riwayat_pasien_lain_ditolak(): void
    {
        $lain = PasienPrb::create([
            'kode_pasien' => 'PRB-8', 'noka' => 'N8', 'fktp_asal' => 'Puskesmas Padasuka', 'diagnosis_prb' => ['Asma'],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif',
        ]);

        $this->delete(route('pasien.realisasi.hapus', [$lain, $this->riwayat('2026-05-24')->id]))->assertSessionHas('info');
        $this->assertSame(4, $this->pasien->riwayatPengambilanObat()->count());
    }
}
