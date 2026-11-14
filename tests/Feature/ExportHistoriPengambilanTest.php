<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Export Data Pasien:
 * - Export biasa (GET): satu sheet, satu baris per pasien, tanpa histori.
 * - Export pasien terpilih (POST ids): sheet pasien + sheet "Histori
 *   Pengambilan" (satu baris per pengambilan), hanya untuk pasien terpilih.
 */
class ExportHistoriPengambilanTest extends TestCase
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

    private function pasien(string $kode, array $riwayat): PasienPrb
    {
        $pasien = PasienPrb::create([
            'kode_pasien'               => $kode,
            'noka'                      => "NOKA {$kode}",
            'fktp_asal'                 => 'Puskesmas A',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2021-01-01',
            'tanggal_jadwal_berikutnya' => '2026-11-19',
            'status'                    => 'Aktif',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
        ]);

        foreach ($riwayat as $i => [$ambil, $jadwal, $selisih, $status]) {
            $pasien->riwayatPengambilanObat()->create([
                'no_faktur'           => "F-{$kode}-{$i}",
                'tanggal_berobat'     => $ambil,
                'tanggal_pengambilan' => $ambil,
                'jadwal_target'       => $jadwal,
                'selisih_hari'        => $selisih,
                'status_kepatuhan'    => $status,
            ]);
        }

        return $pasien;
    }

    public function test_export_biasa_tanpa_histori(): void
    {
        $this->pasien('PRB-0001', [['2026-10-20', '2026-10-20', 0, 'Patuh']]);

        $data = $this->getJson(route('pasien.export'))->assertOk()->json();

        $this->assertCount(1, $data);
        $this->assertSame('PRB-0001', $data[0]['Kode Pasien']);
        $this->assertSame('Catatan', array_key_last($data[0]));
    }

    public function test_export_terpilih_hanya_pasien_dicentang_dengan_histori_urut_dari_terlama(): void
    {
        // Disimpan sengaja tidak urut untuk memastikan export mengurutkan sendiri
        $a = $this->pasien('PRB-0001', [
            ['2026-10-20', '2021-08-17', 1890, 'Tidak Teratur'],
            ['2021-07-18', '2021-07-18', 0, 'Patuh'],
        ]);
        $b = $this->pasien('PRB-0002', [
            ['2026-09-28', '2026-10-01', -3, 'Patuh'],
        ]);
        $this->pasien('PRB-0003', [
            ['2026-10-05', '2026-10-05', 0, 'Patuh'],
        ]);

        $json = $this->postJson(route('pasien.export.terpilih'), ['ids' => [$b->id, $a->id]])
            ->assertOk()->json();

        $this->assertSame(['PRB-0001', 'PRB-0002'], array_column($json['pasien'], 'Kode Pasien'));
        $this->assertSame('Catatan', array_key_last($json['pasien'][0]));

        $this->assertArrayNotHasKey('No. Faktur', $json['histori'][0]);
        $this->assertSame([
            [
                'Kode Pasien' => 'PRB-0001', 'NOKA' => 'NOKA PRB-0001', 'Nama Pasien' => '-', 'Nama FKTP' => 'Puskesmas A',
                'Pengambilan Ke' => 1, 'Tanggal Ambil' => '18/07/2021', 'Jadwal Ambil' => '18/07/2021',
                'Keterangan' => 'Tepat waktu', 'Status Kepatuhan' => 'Patuh',
            ],
            [
                'Kode Pasien' => 'PRB-0001', 'NOKA' => 'NOKA PRB-0001', 'Nama Pasien' => '-', 'Nama FKTP' => 'Puskesmas A',
                'Pengambilan Ke' => 2, 'Tanggal Ambil' => '20/10/2026', 'Jadwal Ambil' => '17/08/2021',
                'Keterangan' => 'Terlambat 1890 hari', 'Status Kepatuhan' => 'Tidak Teratur',
            ],
            [
                'Kode Pasien' => 'PRB-0002', 'NOKA' => 'NOKA PRB-0002', 'Nama Pasien' => '-', 'Nama FKTP' => 'Puskesmas A',
                'Pengambilan Ke' => 1, 'Tanggal Ambil' => '28/09/2026', 'Jadwal Ambil' => '01/10/2026',
                'Keterangan' => '3 hari lebih awal', 'Status Kepatuhan' => 'Patuh',
            ],
        ], $json['histori']);
    }

    public function test_export_pdf_biasa_memuat_histori_sesuai_filter(): void
    {
        $this->pasien('PRB-0001', [['2026-03-05', '2026-03-05', 0, 'Patuh'], ['2026-04-04', '2026-04-04', 0, 'Patuh']]);
        $this->pasien('PRB-0002', [['2026-05-20', '2026-05-20', 0, 'Patuh']]);

        $json = $this->getJson(route('pasien.export', ['dengan_histori' => 1, 'search' => 'PRB-0001']))
            ->assertOk()->json();

        $this->assertSame(['PRB-0001'], array_column($json['pasien'], 'Kode Pasien'));
        $this->assertSame(['05/03/2026', '04/04/2026'], array_column($json['histori'], 'Tanggal Ambil'));
        $this->assertSame([1, 2], array_column($json['histori'], 'Pengambilan Ke'));

        // Tanpa dengan_histori (Excel biasa) tetap daftar pasien saja
        $this->assertCount(2, $this->getJson(route('pasien.export'))->json());
    }

    public function test_export_terpilih_mengabaikan_filter_tabel(): void
    {
        $a = $this->pasien('PRB-0001', [['2026-10-20', '2026-10-20', 0, 'Patuh']]);

        // Filter pencarian yang tidak cocok tidak boleh membuang pasien terpilih
        $json = $this->postJson(route('pasien.export.terpilih'), [
            'ids'    => [$a->id],
            'search' => 'tidak-ada',
        ])->assertOk()->json();

        $this->assertSame(['PRB-0001'], array_column($json['pasien'], 'Kode Pasien'));
        $this->assertCount(1, $json['histori']);
    }

    public function test_export_terpilih_wajib_ada_pasien(): void
    {
        $this->postJson(route('pasien.export.terpilih'), ['ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson(route('pasien.export.terpilih'), ['ids' => ['bukan-uuid']])
            ->assertUnprocessable()->assertJsonValidationErrors('ids.0');
    }

    public function test_hasil_tindak_lanjut_dan_catatan_dari_tindak_lanjut_terakhir(): void
    {
        $a = $this->pasien('PRB-0001', []);
        $this->pasien('PRB-0002', []);

        $a->riwayatTindakLanjut()->create([
            'tanggal_jam' => '2026-10-28 09:00:00', 'petugas' => 'QA',
            'tindakan' => 'Sudah mengambil obat', 'catatan' => 'Ambil di apotek',
        ]);
        $a->riwayatTindakLanjut()->create([
            'tanggal_jam' => '2026-10-29 09:00:00', 'petugas' => 'QA',
            'tindakan' => 'Belum mengambil obat', 'catatan' => 'Stok obat kosong',
        ]);

        $data = $this->getJson(route('pasien.export'))->assertOk()->json();

        $this->assertArrayNotHasKey('Keterangan', $data[0]);
        $this->assertArrayNotHasKey('Tindak Lanjut', $data[0]);
        $this->assertSame(['Hasil Tindak Lanjut', 'Catatan'], array_slice(array_keys($data[0]), -2));
        $this->assertSame('Belum mengambil obat', $data[0]['Hasil Tindak Lanjut']);
        $this->assertSame('Stok obat kosong', $data[0]['Catatan']);
        $this->assertSame('-', $data[1]['Hasil Tindak Lanjut']);
        $this->assertSame('-', $data[1]['Catatan']);
    }
}
