<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PasienPrb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ruang lingkup poin k: audit log mencatat rincian perubahan dan
 * rekamannya tidak dapat diubah/dihapus melalui sistem.
 */
class AuditLogRincianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Petugas Uji']));
    }

    private function pasien(): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien'               => 'PRB-001',
            'noka'                      => 'PRB KPMA 001',
            'fktp_asal'                 => 'Puskesmas A',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-20',
            'status'                    => 'Aktif',
        ]);
    }

    /** @return array<string, array{lama: ?string, baru: ?string}> */
    private function rincianTerakhir(string $aksi): array
    {
        $log = AuditLog::where('aksi', $aksi)->latest()->firstOrFail();
        return collect($log->perubahan)->keyBy('kolom')->all();
    }

    public function test_ubah_pasien_mencatat_nilai_lama_dan_baru_hanya_untuk_field_yang_berubah(): void
    {
        $pasien = $this->pasien();

        $this->put(route('pasien.update', $pasien), [
            'noka'              => 'PRB KPMA 001',
            'fktp_asal'         => 'Puskesmas B',
            'diagnosis_prb'     => ['Hipertensi', 'Diabetes Melitus'],
            'tanggal_mulai_prb' => '2026-01-01',
            'status'            => 'Aktif',
        ])->assertSessionHasNoErrors();

        $rincian = $this->rincianTerakhir('ubah');
        $this->assertSame(['lama' => 'Puskesmas A', 'baru' => 'Puskesmas B'], array_intersect_key($rincian['FKTP'], ['lama' => 1, 'baru' => 1]));
        $this->assertSame('Hipertensi, Diabetes Melitus', $rincian['Diagnosis']['baru']);
        $this->assertArrayNotHasKey('NOKA', $rincian, 'field yang tidak berubah tidak dicatat');
        $this->assertSame('Petugas Uji', AuditLog::where('aksi', 'ubah')->value('petugas'));
    }

    public function test_ubah_jadwal_kini_tercatat_dengan_rincian(): void
    {
        $pasien = $this->pasien();

        $this->put(route('pasien.jadwal.update', $pasien), ['tanggal_jadwal_berikutnya' => '2026-09-25']);

        $rincian = $this->rincianTerakhir('ubah');
        $this->assertSame('20/09/2026', $rincian['Jadwal Berikutnya']['lama']);
        $this->assertSame('25/09/2026', $rincian['Jadwal Berikutnya']['baru']);
    }

    public function test_realisasi_dan_koreksinya_mencatat_status_kepatuhan(): void
    {
        $pasien = $this->pasien();

        // Jadwal 20/09, ambil 30/09 → H+10 → Tidak Patuh
        $this->post(route('pasien.realisasi', $pasien), ['tanggal_realisasi' => '2026-09-30']);
        $rincian = $this->rincianTerakhir('catat_realisasi');
        $this->assertSame('30/09/2026', $rincian['Tanggal Pengambilan']['baru']);
        $this->assertSame('Tidak Patuh', $rincian['Status Kepatuhan']['baru']);
        $this->assertSame('20/09/2026', $rincian['Jadwal Berikutnya']['lama']);
        $this->assertSame('30/10/2026', $rincian['Jadwal Berikutnya']['baru']);

        // Koreksi ke 21/09 → H+1 → Patuh
        $riwayat = $pasien->riwayatPengambilanObat()->firstOrFail();
        $this->put(route('pasien.realisasi.edit', $pasien), [
            'riwayat_id' => $riwayat->id, 'tanggal_realisasi' => '2026-09-21',
        ]);
        $rincian = $this->rincianTerakhir('edit_realisasi');
        $this->assertSame(['30/09/2026', '21/09/2026'], [$rincian['Tanggal Pengambilan']['lama'], $rincian['Tanggal Pengambilan']['baru']]);
        $this->assertSame(['Tidak Patuh', 'Patuh'], [$rincian['Status Kepatuhan']['lama'], $rincian['Status Kepatuhan']['baru']]);
    }

    public function test_hapus_pasien_menyimpan_data_terakhirnya(): void
    {
        $pasien = $this->pasien();
        $this->delete(route('pasien.destroy', $pasien));

        $rincian = $this->rincianTerakhir('hapus');
        $this->assertSame('PRB KPMA 001', $rincian['NOKA']['lama']);
        $this->assertNull($rincian['NOKA']['baru']);
    }

    public function test_hapus_massal_mencatat_identitas_pasien_yang_dihapus(): void
    {
        $pasien = $this->pasien();
        $this->post(route('pasien.destroy-bulk'), ['ids' => [$pasien->id]]);

        $log = AuditLog::where('aksi', 'hapus_massal')->firstOrFail();
        $this->assertSame('PRB-001 (NOKA PRB KPMA 001)', $log->perubahan[0]['lama']);
    }

    public function test_rekaman_audit_tidak_dapat_diubah_atau_dihapus(): void
    {
        $this->post(route('pasien.realisasi', $this->pasien()), ['tanggal_realisasi' => '2026-09-21']);
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['deskripsi' => 'dimanipulasi']);
            $this->fail('Update audit log seharusnya ditolak');
        } catch (\LogicException) {}

        try {
            $log->delete();
            $this->fail('Hapus audit log seharusnya ditolak');
        } catch (\LogicException) {}

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'deskripsi' => $log->getOriginal('deskripsi')]);
    }
}
