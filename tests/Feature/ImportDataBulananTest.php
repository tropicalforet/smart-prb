<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impor data apotek berformat bulanan (kolom MARET/APRIL/MEI berisi tanggal
 * ambil). Frontend memecah satu baris pasien menjadi satu baris per
 * pengambilan — tanpa No. Faktur & tanpa Tanggal Berobat — lalu mengirimnya
 * berurutan secara kronologis.
 */
class ImportDataBulananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function baris(string $noka, ?string $mulai, ?string $ambil, array $extra = []): array
    {
        return array_filter([
            'noka'                => $noka,
            'fktp_asal'           => 'KPMA',
            'diagnosis_prb'       => ['Diabetes Melitus'],
            'tanggal_mulai_prb'   => $mulai,
            'tanggal_pengambilan' => $ambil,
        ] + $extra, fn ($v) => $v !== null);
    }

    private function impor(array $rows)
    {
        return $this->postJson(route('pasien.import'), ['data' => $rows]);
    }

    public function test_tiga_pengambilan_bulanan_menjadi_tiga_riwayat_dengan_baseline(): void
    {
        $this->impor([
            $this->baris('PRB KPMA 001', '2018-09-07', '2026-03-20'),
            $this->baris('PRB KPMA 001', '2018-09-07', '2026-04-20'),
            $this->baris('PRB KPMA 001', '2018-09-07', '2026-05-28'),
        ])->assertOk()->assertExactJson(['baru' => 1, 'diperbarui' => 2, 'duplikat' => 0, 'invalid' => 0]);

        $pasien  = PasienPrb::where('noka', 'PRB KPMA 001')->firstOrFail();
        $riwayat = $pasien->riwayatPengambilanObat()->orderBy('tanggal_pengambilan')->get();

        $this->assertCount(3, $riwayat);

        // Maret: data pertama pasien lama (mulai 2018) → baseline, bukan dinilai thd jadwal 2018
        $this->assertSame('2026-03-20', $riwayat[0]->jadwal_target->toDateString());
        $this->assertSame(0, $riwayat[0]->selisih_hari);

        // April: target = Maret + 30 = 19/04 → ambil 20/04 → H+1 → Patuh
        $this->assertSame('2026-04-19', $riwayat[1]->jadwal_target->toDateString());
        $this->assertSame(1, $riwayat[1]->selisih_hari);
        $this->assertSame('Patuh', $riwayat[1]->status_kepatuhan);

        // Mei: target = April + 30 = 20/05 → ambil 28/05 → H+8 → Tidak Patuh
        $this->assertSame('2026-05-20', $riwayat[2]->jadwal_target->toDateString());
        $this->assertSame(8, $riwayat[2]->selisih_hari);
        $this->assertSame('Tidak Patuh', $riwayat[2]->status_kepatuhan);

        $this->assertSame('Lama', $pasien->jenis_prb);
        $this->assertSame('2026-06-27', $pasien->tanggal_jadwal_berikutnya->toDateString());
    }

    public function test_dua_pengambilan_di_bulan_yang_sama_tidak_dianggap_duplikat(): void
    {
        $this->impor([
            $this->baris('PRB AH1 063', '2016-02-01', '2026-03-01'),
            $this->baris('PRB AH1 063', '2016-02-01', '2026-03-29'),
        ])->assertOk()->assertJson(['duplikat' => 0]);

        $this->assertSame(2, PasienPrb::where('noka', 'PRB AH1 063')->firstOrFail()->riwayatPengambilanObat()->count());
    }

    public function test_impor_ulang_file_yang_sama_terdeteksi_duplikat(): void
    {
        $rows = [
            $this->baris('PRB SENO 039', '2018-09-07', '2026-03-20'),
            $this->baris('PRB SENO 039', '2018-09-07', '2026-04-20'),
        ];

        $this->impor($rows)->assertOk();
        $this->impor($rows)->assertOk()->assertExactJson(['baru' => 0, 'diperbarui' => 0, 'duplikat' => 2, 'invalid' => 0]);

        $this->assertSame(2, PasienPrb::where('noka', 'PRB SENO 039')->firstOrFail()->riwayatPengambilanObat()->count());
    }

    public function test_pasien_tanpa_tanggal_mulai_tetap_diimpor_sebagai_prb_lama(): void
    {
        $this->impor([
            $this->baris('PRB GRY 004', null, '2026-03-06'),
            $this->baris('PRB GRY 004', null, '2026-05-06'),
        ])->assertOk()->assertJson(['invalid' => 0]);

        $pasien = PasienPrb::where('noka', 'PRB GRY 004')->firstOrFail();
        $this->assertNull($pasien->tanggal_mulai_prb);
        $this->assertSame('Lama', $pasien->jenis_prb);
        $this->assertSame(30, $pasien->jatah_obat);
        $this->assertSame('2026-06-05', $pasien->tanggal_jadwal_berikutnya->toDateString());
    }

    public function test_pasien_tanpa_tanggal_mulai_dan_tanpa_pengambilan_tidak_diberi_jadwal_karangan(): void
    {
        $this->impor([$this->baris('PRB IBRA 075', null, null)])->assertOk()->assertJson(['baru' => 1]);

        $this->assertNull(PasienPrb::where('noka', 'PRB IBRA 075')->firstOrFail()->tanggal_jadwal_berikutnya);
    }

    public function test_prb_baru_pengambilan_pertama_dinilai_terhadap_mulai_plus_23(): void
    {
        // Mulai 20/02 → jadwal pertama 15/03 → ambil 14/03 → H-1 → Patuh
        $this->impor([
            $this->baris('PRB SBY 018', '2026-02-20', '2026-03-14'),
        ])->assertOk();

        $riwayat = PasienPrb::where('noka', 'PRB SBY 018')->firstOrFail()->riwayatPengambilanObat()->firstOrFail();
        $this->assertSame('2026-03-15', $riwayat->jadwal_target->toDateString());
        $this->assertSame(-1, $riwayat->selisih_hari);
        $this->assertSame('Patuh', $riwayat->status_kepatuhan);
    }

    public function test_jadwal_pertama_lewat_lebih_dari_sebulan_menjadi_baseline(): void
    {
        // Mulai 15/01 → jadwal pertama 07/02. Data apotek baru ada mulai Maret:
        // pengambilan Februari tidak tercatat, jadi 12/03 bukan "telat 33 hari"
        // melainkan titik awal pengamatan.
        $this->impor([
            $this->baris('PRB IBRA 123', '2026-01-15', '2026-03-12'),
        ])->assertOk();

        $riwayat = PasienPrb::where('noka', 'PRB IBRA 123')->firstOrFail()->riwayatPengambilanObat()->firstOrFail();
        $this->assertSame('2026-03-12', $riwayat->jadwal_target->toDateString());
        $this->assertSame(0, $riwayat->selisih_hari);
    }

    public function test_import_bulanan_oktober_lalu_november_seperti_contoh_asep(): void
    {
        // Data November baru di-import di bulan November (tanggal ambil tidak boleh di masa depan)
        \Carbon\Carbon::setTestNow('2026-11-15');

        // Oktober: mulai 01/09, ambil 01/10 → mulai sebelum bulan ambil → PRB Lama → jadwal 31/10
        $this->impor([$this->baris('PRB ASEP 001', '2026-09-01', '2026-10-01')])->assertOk();

        $pasien = PasienPrb::where('noka', 'PRB ASEP 001')->firstOrFail();
        $this->assertSame('Lama', $pasien->jenis_prb);
        $this->assertSame('2026-10-01', $pasien->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-10-31', $pasien->tanggal_jadwal_berikutnya->toDateString());

        // November: ambil 31/10 = tepat jadwal → Patuh, jadwal berikutnya 31/10 + 30 = 30/11
        $this->impor([$this->baris('PRB ASEP 001', '2026-09-01', '2026-10-31')])->assertOk();

        $pasien->refresh();
        $this->assertSame('2026-10-31', $pasien->tanggal_pengambilan_terakhir->toDateString());
        $this->assertSame('2026-11-30', $pasien->tanggal_jadwal_berikutnya->toDateString());

        $riwayat = $pasien->riwayatPengambilanObat()->orderBy('tanggal_pengambilan')->get();
        $this->assertCount(2, $riwayat);
        $this->assertSame('2026-10-31', $riwayat[1]->jadwal_target->toDateString());
        $this->assertSame(0, $riwayat[1]->selisih_hari);
        $this->assertSame('Patuh', $riwayat[1]->status_kepatuhan);

        \Carbon\Carbon::setTestNow();
    }

    public function test_prb_baru_yang_sudah_ambil_dijadwalkan_dari_tanggal_ambil(): void
    {
        // Mulai & ambil di bulan yang sama (Maret) → PRB Baru, jatah 23 dari tanggal ambil
        $this->impor([
            $this->baris('PRB BAKSAR 049', '2026-03-05', '2026-03-28'),
        ])->assertOk();

        $pasien = PasienPrb::where('noka', 'PRB BAKSAR 049')->firstOrFail();
        $this->assertSame('Baru', $pasien->jenis_prb);
        $this->assertSame('2026-04-20', $pasien->tanggal_jadwal_berikutnya->toDateString());
    }
}
