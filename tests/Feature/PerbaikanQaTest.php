<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regresi untuk temuan QA 27/10/2026.
 */
class PerbaikanQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pasien(string $noka = 'QA 001'): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien'               => 'PRB-' . substr(md5($noka), 0, 6),
            'noka'                      => $noka,
            'fktp_asal'                 => 'Puskesmas A',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'tanggal_jadwal_berikutnya' => '2026-10-20',
            'status'                    => 'Aktif',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
        ]);
    }

    public function test_tanggal_pengambilan_di_masa_depan_ditolak_dengan_pesan_indonesia(): void
    {
        $pasien = $this->pasien();

        $this->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-10-28'])
            ->assertSessionHasErrors(['tanggal_realisasi' => 'Tanggal pengambilan tidak boleh melewati hari ini.']);

        $this->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-10-27'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $pasien->riwayatPengambilanObat()->count());
    }

    public function test_import_menolak_tanggal_pengambilan_masa_depan(): void
    {
        $this->postJson(route('pasien.import'), ['data' => [[
            'noka'                => 'QA IMPOR',
            'fktp_asal'           => 'KPMA',
            'diagnosis_prb'       => ['Asma'],
            'tanggal_mulai_prb'   => '2026-08-01',
            'tanggal_pengambilan' => '2027-01-01',
        ]]])->assertOk()->assertJson(['invalid' => 1, 'baru' => 0]);

        $this->assertDatabaseMissing('pasien_prb', ['noka' => 'QA IMPOR']);
    }

    public function test_realisasi_di_tanggal_lama_setelah_tanggalnya_dikoreksi_tidak_dianggap_duplikat(): void
    {
        $pasien = $this->pasien();
        $this->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-10-01']);
        $riwayat = $pasien->riwayatPengambilanObat()->firstOrFail();

        // Koreksi tanggal 01/10 → 02/10: no_faktur masih memuat 20261001
        $this->put(route('pasien.realisasi.edit', $pasien), [
            'riwayat_id' => $riwayat->id, 'tanggal_realisasi' => '2026-10-02',
        ])->assertSessionHasNoErrors();

        // Pengambilan baru tepat di 01/10 harus tetap bisa dicatat
        $this->post(route('pasien.realisasi', $pasien->fresh()), ['tanggal_realisasi' => '2026-10-01'])
            ->assertSessionHas('success');

        $this->assertSame(
            ['2026-10-01', '2026-10-02'],
            $pasien->riwayatPengambilanObat()->orderBy('tanggal_pengambilan')->get()
                ->map(fn ($r) => $r->tanggal_pengambilan->toDateString())->all()
        );
    }

    public function test_hapus_massal_hanya_menghapus_yang_dipilih(): void
    {
        $a = $this->pasien('QA A');
        $b = $this->pasien('QA B');

        // Opsi "hapus semua" tidak ada lagi
        $this->post(route('pasien.destroy-bulk'), ['all' => true])->assertSessionHasErrors('ids');
        $this->assertSame(2, PasienPrb::count());

        $this->post(route('pasien.destroy-bulk'), ['ids' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertModelMissing($a);
        $this->assertModelExists($b);
    }

    public function test_ubah_data_pasien_menjadi_pasif_dan_kembali_aktif(): void
    {
        $pasien = $this->pasien();
        $data = [
            'noka' => 'QA 001', 'fktp_asal' => 'Puskesmas B', 'diagnosis_prb' => ['Asma'],
            'tanggal_mulai_prb' => '2026-01-01',
        ];

        $this->put(route('pasien.update', $pasien), $data + ['status' => 'Pasif'])
            ->assertSessionHasErrors(['alasan_pasif' => 'Alasan pasif wajib diisi jika status adalah Pasif.']);

        $this->put(route('pasien.update', $pasien), $data + ['status' => 'Pasif', 'alasan_pasif' => 'Pindah Faskes'])
            ->assertSessionHasNoErrors();
        $pasien->refresh();
        $this->assertSame(['Pasif', 'Pindah Faskes', 'Puskesmas B'], [$pasien->status, $pasien->alasan_pasif, $pasien->fktp_asal]);

        $this->put(route('pasien.update', $pasien), $data + ['status' => 'Aktif', 'alasan_pasif' => null])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Aktif', null], [$pasien->fresh()->status, $pasien->fresh()->alasan_pasif]);
    }

    public function test_tren_dashboard_ikut_berubah_setelah_ada_realisasi_baru(): void
    {
        $pasien = $this->pasien();

        $trenOktober = function () {
            $nilai = null;
            $this->get('/')->assertInertia(function (Assert $page) use (&$nilai) {
                $nilai = collect($page->toArray()['props']['trenKepatuhan'])->firstWhere('periode', '2026-10');
            });
            return [$nilai['patuh'], $nilai['tidak_patuh']];
        };

        // Jadwal 20/10 terlewat tanpa realisasi → Tidak Teratur (hasil ini ter-cache)
        $this->assertSame([0, 1], $trenOktober());

        // Realisasi 21/10 (H+1) → Patuh; cache harus ikut diperbarui
        $this->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-10-21']);
        $this->assertSame([1, 0], $trenOktober());
    }
}
