<?php

namespace Tests\Unit;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KepatuhanServiceTest extends TestCase
{
    use RefreshDatabase;

    private KepatuhanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KepatuhanService();
    }

    /**
     * CONTOH 1:
     * Jadwal 18/09/2026, Evaluasi 22/09/2026 (H+4)
     * Hasil: Early Warning = Overdue, Status = Tidak Patuh, Keterangan = "Terlambat 4 hari, melewati batas toleransi H+3"
     */
    public function test_contoh_1_overdue_h_plus_4()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-22');

        $this->assertEquals('Tidak Patuh', $result['status']);
        $this->assertEquals('Merah - Tidak patuh/Overdue', $result['early_warning']);
        $this->assertEquals(4, $result['selisih_hari']);
        $this->assertEquals('Terlambat 4 hari, melewati batas toleransi H+3', $result['keterangan']);
    }

    /**
     * CONTOH 2:
     * Jadwal 18/09/2026, Evaluasi 21/09/2026 (H+3)
     * Hasil: Early Warning = Masih Toleransi, Status = Dalam Toleransi
     */
    public function test_contoh_2_masih_toleransi_h_plus_3()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-21');

        $this->assertEquals('Dalam Toleransi', $result['status']);
        $this->assertEquals('Oranye - Belum mengambil, masih toleransi', $result['early_warning']);
        $this->assertEquals(3, $result['selisih_hari']);
    }

    /**
     * CONTOH 3:
     * Jadwal 18/09/2026, Evaluasi 18/09/2026 (Hari H)
     * Hasil: Early Warning = Hari Pengambilan, Status = Dalam Toleransi
     */
    public function test_contoh_3_hari_pengambilan_hari_h()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-18');

        $this->assertEquals('Dalam Toleransi', $result['status']);
        $this->assertEquals('Oranye - Jatuh tempo', $result['early_warning']);
        $this->assertEquals(0, $result['selisih_hari']);
    }

    /**
     * CONTOH 4:
     * Jadwal 18/09/2026, Evaluasi 15/09/2026 (H-3)
     * Hasil: Early Warning = Jadwal Mendekat, Status = Dalam Toleransi
     */
    public function test_contoh_4_jadwal_mendekat_h_min_3()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-15');

        $this->assertEquals('Dalam Toleransi', $result['status']);
        $this->assertEquals('Kuning - Jadwal mendekat', $result['early_warning']);
        $this->assertEquals(-3, $result['selisih_hari']);
    }

    /**
     * CONTOH 5:
     * Jadwal 18/09/2026, Evaluasi 14/09/2026 (H-4)
     * Hasil: Early Warning = Belum Jatuh Tempo, Status = Belum Jatuh Tempo
     */
    public function test_contoh_5_belum_jatuh_tempo_sebelum_h_min_3()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-14');

        $this->assertEquals('Belum Jatuh Tempo', $result['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $result['early_warning']);
        $this->assertEquals(-4, $result['selisih_hari']);
    }

    /**
     * CONTOH 6:
     * Pasien Pasif
     * Hasil: Early Warning = Tidak Berlaku, Status = Pasif
     */
    public function test_contoh_6_pasien_pasif()
    {
        $pasien = new PasienPrb([
            'status' => 'Pasif',
            'alasan_pasif' => 'Rujuk Balik ke RS',
            'tanggal_jadwal_berikutnya' => '2026-09-18',
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-22');

        $this->assertEquals('Pasif', $result['status']);
        $this->assertEquals('Abu-abu', $result['early_warning']);
    }

    /**
     * CONTOH OVERDUE LAIN:
     * Jadwal 07/09/2026, Evaluasi 22/09/2026 (H+15)
     * Keterangan: "Terlambat 15 hari, melewati batas toleransi H+3"
     */
    public function test_overdue_h_plus_15()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-07',
            'tanggal_realisasi' => null,
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-22');

        $this->assertEquals('Tidak Patuh', $result['status']);
        $this->assertEquals('Merah - Tidak patuh/Overdue', $result['early_warning']);
        $this->assertEquals(15, $result['selisih_hari']);
        $this->assertEquals('Terlambat 15 hari, melewati batas toleransi H+3', $result['keterangan']);
    }

    /**
     * Realisasi pengambilan obat dalam toleransi (H-3 s.d. H+3) = Patuh
     */
    public function test_realisasi_dalam_toleransi()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-20',
            'tanggal_realisasi' => '2026-09-22',
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-25');

        $this->assertEquals('Patuh', $result['status']);
        $this->assertEquals('Hijau - Patuh', $result['early_warning']);
    }

    /**
     * Realisasi terlalu awal (< H-3) = Tidak Patuh
     */
    public function test_realisasi_terlalu_awal()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-20',
            'tanggal_realisasi' => '2026-09-10',
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-25');

        $this->assertEquals('Tidak Patuh', $result['status']);
        $this->assertEquals('Terlambat 5 hari, melewati batas toleransi H+3', $result['keterangan']);
    }

    /**
     * Realisasi terlambat (> H+3) = Tidak Patuh
     */
    public function test_realisasi_terlambat()
    {
        $pasien = new PasienPrb([
            'status' => 'Aktif',
            'tanggal_jadwal_berikutnya' => '2026-09-20',
            'tanggal_realisasi' => '2026-09-25',
        ]);

        $result = $this->service->tentukanStatusKepatuhan($pasien, '2026-09-25');

        $this->assertEquals('Tidak Patuh', $result['status']);
        $this->assertEquals('Terlambat 5 hari, melewati batas toleransi H+3', $result['keterangan']);
    }

    /**
     * CONTOH FILTER:
     * Filter "Patuh" hanya menghasilkan pasien dengan status_kepatuhan = "Patuh".
     * Pasien Tidak Patuh, Pasif, Belum Jatuh Tempo tidak boleh lolos filter.
     */
    public function test_filter_dalam_toleransi()
    {
        $evaluasi = '2026-09-22';

        $pasienA = new PasienPrb(['nama_pasien' => 'A', 'status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-21', 'tanggal_realisasi' => '2026-09-22']); // Patuh
        $pasienB = new PasienPrb(['nama_pasien' => 'B', 'status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-18']); // H+4 -> Tidak Patuh
        $pasienC = new PasienPrb(['nama_pasien' => 'C', 'status' => 'Pasif', 'alasan_pasif' => 'Meninggal Dunia']); // Pasif -> Pasif
        $pasienD = new PasienPrb(['nama_pasien' => 'D', 'status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-28']); // H-6 -> Belum Jatuh Tempo

        $list = collect([$pasienA, $pasienB, $pasienC, $pasienD])->map(function ($p) use ($evaluasi) {
            $kepatuhan = $this->service->tentukanStatusKepatuhan($p, $evaluasi);
            return [
                'nama_pasien' => $p->nama_pasien,
                'status_kepatuhan' => $kepatuhan['status'],
                'early_warning' => $kepatuhan['early_warning'],
            ];
        });

        $filterKepatuhan = 'Patuh';
        $filtered = $list->filter(fn($p) => $p['status_kepatuhan'] === $filterKepatuhan)->values();

        $this->assertCount(1, $filtered);
        $this->assertEquals('A', $filtered[0]['nama_pasien']);
        $this->assertEquals('Patuh', $filtered[0]['status_kepatuhan']);
    }

    /**
     * TEST SKENARIO PASIEN PRB-002:
     * - Mulai PRB: 24/07/2026 (PRB Baru -> Jatah 23 hari -> Target: 16/08/2026)
     * - Realisasi Pengambilan: 24/09/2026
     * - Tanggal Evaluasi: 24/09/2026
     * 
     * Verifikasi Urutan & Nilai:
     * 1. Jadwal Target = 16/08/2026
     * 2. Realisasi = 24/09/2026
     * 3. Selisih Hari = 39 hari
     * 4. Status Kepatuhan = "Tidak Patuh"
     * 5. Record histori riwayat_pengambilan_obat tersimpan secara permanen
     * 6. Jadwal Berikutnya baru dihitung = 24/10/2026 (24/09/2026 + 30 hari)
     * 7. Histori transaksi lama tidak berubah setelah Jadwal Berikutnya diperbarui.
     */
    public function test_skenario_prb_002_target_16_agustus_realisasi_24_september()
    {
        // 1. Buat pasien PRB Baru (Mulai 24/07/2026)
        $pasien = PasienPrb::create([
            'kode_pasien' => 'PRB-002',
            'nama_pasien' => 'Pasien PRB 002',
            'no_kartu_bpjs' => '0001234567890',
            'fktp_asal' => 'Puskesmas Kebayoran',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-07-24',
            'jenis_prb' => 'Baru',
            'jatah_obat' => 23,
            'tanggal_jadwal_berikutnya' => '2026-08-16', // 24/07/2026 + 23 hari
            'status' => 'Aktif',
        ]);

        $this->assertEquals('2026-08-16', $pasien->tanggal_jadwal_berikutnya->toDateString());

        // 2. Petugas memproses realisasi 24/09/2026
        $pasienUpdated = $this->service->prosesRealisasi($pasien, '2026-09-24');

        // 3. Verifikasi pembaruan pasien master
        $this->assertEquals('2026-09-24', $pasienUpdated->tanggal_pengambilan_terakhir->toDateString());
        $this->assertEquals('2026-10-24', $pasienUpdated->tanggal_jadwal_berikutnya->toDateString());
        $this->assertEquals('Lama', $pasienUpdated->jenis_prb);
        $this->assertEquals(30, $pasienUpdated->jatah_obat);

        // 4. Verifikasi record histori riwayat_pengambilan_obat di DB
        $riwayat = $pasienUpdated->riwayatPengambilanObat()->first();
        $this->assertNotNull($riwayat);
        $this->assertEquals('2026-09-24', $riwayat->tanggal_pengambilan->toDateString());
        $this->assertEquals('2026-08-16', $riwayat->jadwal_target->toDateString());
        $this->assertEquals(39, $riwayat->selisih_hari);
        $this->assertEquals('Tidak Patuh', $riwayat->status_kepatuhan);

        // 5. Verifikasi tentukanStatusKepatuhan pada tanggal evaluasi 24/09/2026 untuk siklus aktif (24/10/2026) -> Belum Jatuh Tempo
        $kepatuhan = $this->service->tentukanStatusKepatuhan($pasienUpdated, '2026-09-24');
        $this->assertEquals('Belum Jatuh Tempo', $kepatuhan['status']); // Berdiri sendiri, tidak mewarisi riwayat terakhir
        $this->assertEquals('Biru - Belum memasuki jadwal', $kepatuhan['early_warning']);

        // 6. Pastikan histori transaksi 24/09/2026 tidak berubah meskipun pasien kemudian masuk ke siklus berikutnya
        $riwayatReloaded = $pasienUpdated->riwayatPengambilanObat()->first();
        $this->assertEquals('2026-08-16', $riwayatReloaded->jadwal_target->toDateString());
        $this->assertEquals(39, $riwayatReloaded->selisih_hari);
        $this->assertEquals('Tidak Patuh', $riwayatReloaded->status_kepatuhan);
    }

    /**
     * VERIFIKASI 5 PASIEN CONTOH USER (Hari Ini: 24/09/2026):
     * 1. Ahmad Sudirman: Terakhir Ambil = 24/09/2026, Jadwal = 24/10/2026 (H-30) -> Belum Jatuh Tempo
     * 2. Siti Aminah: Terakhir Ambil = 24/09/2026, Jadwal = 24/10/2026 (H-30) -> Belum Jatuh Tempo
     * 3. Test H Plus: Terakhir Ambil = 02/09/2026, Jadwal = 02/10/2026 (H-8) -> Belum Jatuh Tempo
     * 4. test f: Terakhir Ambil = 23/09/2026, Jadwal = 23/10/2026 (H-29) -> Belum Jatuh Tempo
     * 5. TEST H: Terakhir Ambil = 21/09/2026, Jadwal = 21/10/2026 (H-27) -> Belum Jatuh Tempo
     */
    public function test_5_pasien_contoh_user()
    {
        $evaluasi = '2026-09-24';

        // 1. Ahmad Sudirman (Jadwal 24/10/2026)
        $ahmad = new PasienPrb([
            'nama_pasien' => 'Ahmad Sudirman',
            'status' => 'Aktif',
            'tanggal_pengambilan_terakhir' => '2026-09-24',
            'tanggal_jadwal_berikutnya' => '2026-10-24',
        ]);
        $resAhmad = $this->service->tentukanStatusKepatuhan($ahmad, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resAhmad['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resAhmad['early_warning']);

        // 2. Siti Aminah (Jadwal 24/10/2026)
        $siti = new PasienPrb([
            'nama_pasien' => 'Siti Aminah',
            'status' => 'Aktif',
            'tanggal_pengambilan_terakhir' => '2026-09-24',
            'tanggal_jadwal_berikutnya' => '2026-10-24',
        ]);
        $resSiti = $this->service->tentukanStatusKepatuhan($siti, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resSiti['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resSiti['early_warning']);

        // 3. Test H Plus (Jadwal 02/10/2026)
        $testHPlus = new PasienPrb([
            'nama_pasien' => 'Test H Plus',
            'status' => 'Aktif',
            'tanggal_pengambilan_terakhir' => '2026-09-02',
            'tanggal_jadwal_berikutnya' => '2026-10-02',
        ]);
        $resHPlus = $this->service->tentukanStatusKepatuhan($testHPlus, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resHPlus['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resHPlus['early_warning']);

        // 4. test f (Jadwal 23/10/2026)
        $testF = new PasienPrb([
            'nama_pasien' => 'test f',
            'status' => 'Aktif',
            'tanggal_pengambilan_terakhir' => '2026-09-23',
            'tanggal_jadwal_berikutnya' => '2026-10-23',
        ]);
        $resF = $this->service->tentukanStatusKepatuhan($testF, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resF['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resF['early_warning']);

        // 5. TEST H (Jadwal 21/10/2026)
        $testH = new PasienPrb([
            'nama_pasien' => 'TEST H',
            'status' => 'Aktif',
            'tanggal_pengambilan_terakhir' => '2026-09-21',
            'tanggal_jadwal_berikutnya' => '2026-10-21',
        ]);
        $resH = $this->service->tentukanStatusKepatuhan($testH, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resH['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resH['early_warning']);
    }

    /**
     * VALIDASI WAJIB SKENARIO A - I (Tanggal Evaluasi Sistem: 24/09/2026):
     * A. Jadwal 30/09 -> Belum Jatuh Tempo
     * B. Jadwal 27/09 -> H-3 -> Dalam Toleransi jika belum realisasi
     * C. Jadwal 24/09 -> H -> Dalam Toleransi jika belum realisasi
     * D. Jadwal 21/09 -> H+3 -> Dalam Toleransi jika belum realisasi
     * E. Jadwal 20/09 -> H+4 -> Tidak Patuh / Overdue
     * F. Realisasi 27/09 untuk jadwal 30/09 -> Patuh (H-3)
     * G. Realisasi 24/09 untuk jadwal 24/09 -> Patuh (H)
     * H. Realisasi 21/09 untuk jadwal 18/09 -> H+3 -> Patuh
     * I. Realisasi 24/09 untuk jadwal 19/09 -> H+5 -> Tidak Patuh
     */
    public function test_validasi_wajib_skenario_a_sampai_i()
    {
        $evaluasi = '2026-09-24';

        // A. Jadwal 30/09 -> Belum Jatuh Tempo
        $pA = new PasienPrb(['status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-30']);
        $resA = $this->service->tentukanStatusKepatuhan($pA, $evaluasi);
        $this->assertEquals('Belum Jatuh Tempo', $resA['status']);
        $this->assertEquals('Biru - Belum memasuki jadwal', $resA['early_warning']);

        // B. Jadwal 27/09 -> H-3 -> Dalam Toleransi jika belum realisasi
        $pB = new PasienPrb(['status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-27']);
        $resB = $this->service->tentukanStatusKepatuhan($pB, $evaluasi);
        $this->assertEquals('Dalam Toleransi', $resB['status']);
        $this->assertEquals('Kuning - Jadwal mendekat', $resB['early_warning']);

        // C. Jadwal 24/09 -> H -> Dalam Toleransi jika belum realisasi
        $pC = new PasienPrb(['status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-24']);
        $resC = $this->service->tentukanStatusKepatuhan($pC, $evaluasi);
        $this->assertEquals('Dalam Toleransi', $resC['status']);
        $this->assertEquals('Oranye - Jatuh tempo', $resC['early_warning']);

        // D. Jadwal 21/09 -> H+3 -> Dalam Toleransi jika belum realisasi
        $pD = new PasienPrb(['status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-21']);
        $resD = $this->service->tentukanStatusKepatuhan($pD, $evaluasi);
        $this->assertEquals('Dalam Toleransi', $resD['status']);
        $this->assertEquals('Oranye - Belum mengambil, masih toleransi', $resD['early_warning']);

        // E. Jadwal 20/09 -> H+4 -> Tidak Patuh / Overdue
        $pE = new PasienPrb(['status' => 'Aktif', 'tanggal_jadwal_berikutnya' => '2026-09-20']);
        $resE = $this->service->tentukanStatusKepatuhan($pE, $evaluasi);
        $this->assertEquals('Tidak Patuh', $resE['status']);
        $this->assertEquals('Merah - Tidak patuh/Overdue', $resE['early_warning']);

        // F. Realisasi 27/09 untuk jadwal 30/09 -> Patuh (H-3)
        $pF = PasienPrb::create([
            'kode_pasien' => 'PRB-F',
            'nama_pasien' => 'Pasien F',
            'no_kartu_bpjs' => '0001000000001',
            'fktp_asal' => 'Klinik F',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-08-01',
            'jenis_prb' => 'Lama',
            'jatah_obat' => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-30',
            'status' => 'Aktif',
        ]);
        $pFUpdated = $this->service->prosesRealisasi($pF, '2026-09-27');
        // Histori tersimpan
        $riwayatF = $pFUpdated->riwayatPengambilanObat()->first();
        $this->assertEquals('Patuh', $riwayatF->status_kepatuhan);

        // G. Realisasi 24/09 untuk jadwal 24/09 -> Patuh (H)
        $pG = PasienPrb::create([
            'kode_pasien' => 'PRB-G',
            'nama_pasien' => 'Pasien G',
            'no_kartu_bpjs' => '0001000000002',
            'fktp_asal' => 'Klinik G',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-08-01',
            'jenis_prb' => 'Lama',
            'jatah_obat' => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-24',
            'status' => 'Aktif',
        ]);
        $pGUpdated = $this->service->prosesRealisasi($pG, '2026-09-24');
        $riwayatG = $pGUpdated->riwayatPengambilanObat()->first();
        $this->assertEquals('Patuh', $riwayatG->status_kepatuhan);

        // H. Realisasi 21/09 untuk jadwal 18/09 -> H+3 -> Patuh
        $pH = PasienPrb::create([
            'kode_pasien' => 'PRB-H',
            'nama_pasien' => 'Pasien H',
            'no_kartu_bpjs' => '0001000000003',
            'fktp_asal' => 'Klinik H',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-08-01',
            'jenis_prb' => 'Lama',
            'jatah_obat' => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-18',
            'status' => 'Aktif',
        ]);
        $pHUpdated = $this->service->prosesRealisasi($pH, '2026-09-21');
        $riwayatH = $pHUpdated->riwayatPengambilanObat()->first();
        $this->assertEquals('Patuh', $riwayatH->status_kepatuhan);

        // I. Realisasi 24/09 untuk jadwal 19/09 -> H+5 -> Tidak Patuh
        $pI = PasienPrb::create([
            'kode_pasien' => 'PRB-I',
            'nama_pasien' => 'Pasien I',
            'no_kartu_bpjs' => '0001000000004',
            'fktp_asal' => 'Klinik I',
            'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-08-01',
            'jenis_prb' => 'Lama',
            'jatah_obat' => 30,
            'tanggal_jadwal_berikutnya' => '2026-09-19',
            'status' => 'Aktif',
        ]);
        $pIUpdated = $this->service->prosesRealisasi($pI, '2026-09-24');
        $riwayatI = $pIUpdated->riwayatPengambilanObat()->first();
        $this->assertEquals('Tidak Patuh', $riwayatI->status_kepatuhan);
    }
}

