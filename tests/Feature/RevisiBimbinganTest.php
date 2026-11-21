<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\User;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Revisi bimbingan (November 2026): status Meninggal, kategori tindak lanjut,
 * periode penelitian Maret–Mei, aturan PRB Baru Mei, nama tersamar, dan
 * urutan Prioritas Early Warning.
 */
class RevisiBimbinganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-05');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function penelitianMaretMei(): void
    {
        config(['prb.periode_penelitian' => ['2026-03', '2026-04', '2026-05']]);
    }

    private function pasien(string $kode, array $atribut = []): PasienPrb
    {
        return PasienPrb::create(array_merge([
            'kode_pasien'               => $kode,
            'noka'                      => "NOKA {$kode}",
            'fktp_asal'                 => 'Puskesmas Padasuka',
            'diagnosis_prb'             => ['Hipertensi'],
            'tanggal_mulai_prb'         => '2026-01-01',
            'tanggal_jadwal_berikutnya' => '2026-06-10',
            'status'                    => 'Aktif',
            'jenis_prb'                 => 'Lama',
            'jatah_obat'                => 30,
        ], $atribut));
    }

    // ── Status Meninggal ────────────────────────────────────────────────

    public function test_status_meninggal_keluar_dari_pemantauan(): void
    {
        $pasien = $this->pasien('PRB-001');

        $this->put(route('pasien.update', $pasien), [
            'noka' => $pasien->noka, 'fktp_asal' => 'Puskesmas Padasuka', 'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2026-01-01', 'status' => 'Meninggal', 'alasan_pasif' => 'Pindah Faskes',
        ])->assertSessionHasNoErrors();

        $pasien->refresh();
        $this->assertSame('Meninggal', $pasien->status);
        $this->assertNull($pasien->alasan_pasif, 'alasan pasif hanya untuk status Pasif');

        $k = app(KepatuhanService::class)->tentukanStatusKepatuhan($pasien);
        $this->assertSame('Meninggal', $k['status']);
        $this->assertSame('Pasien meninggal dunia', $k['keterangan']);

        $this->assertNotContains('Meninggal Dunia', PasienPrb::ALASAN_PASIF_OPTIONS);
    }

    // ── Kategori tindak lanjut ─────────────────────────────────────────

    public function test_kategori_tindak_lanjut_wajib_dan_meninggal_mengubah_status(): void
    {
        $pasien = $this->pasien('PRB-001');

        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Belum mengambil obat'])
            ->assertSessionHasErrors('kategori');
        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Belum mengambil obat', 'kategori' => 'Asal'])
            ->assertSessionHasErrors('kategori');
        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Belum mengambil obat', 'kategori' => 'Membeli obat sendiri'])
            ->assertSessionHasErrors('kategori');
        $this->assertSame(
            ['Kontak langsung', 'Koordinasi FKTP', 'Meninggal', 'Kembali ke FKRTL', 'Lain-lain'],
            \App\Models\RiwayatTindakLanjut::KATEGORI_OPTIONS
        );

        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Belum mengambil obat', 'kategori' => 'Koordinasi FKTP'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Aktif', $pasien->fresh()->status);

        // Sudah mengambil obat: tanpa kategori; kategori yang terkirim diabaikan
        $this->post(route('tindak-lanjut.store', $pasien), [
            'tindakan' => 'Sudah mengambil obat', 'tanggal_realisasi' => '2026-06-04', 'kategori' => 'Meninggal',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Aktif', $pasien->fresh()->status, 'kategori diabaikan untuk Sudah mengambil obat');
        $this->assertNull($pasien->riwayatTindakLanjut()->where('tindakan', 'Sudah mengambil obat')->value('kategori'));

        $this->post(route('tindak-lanjut.store', $pasien), ['tindakan' => 'Belum mengambil obat', 'kategori' => 'Meninggal'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Meninggal', $pasien->fresh()->status);
        $this->assertSame(['Koordinasi FKTP', 'Meninggal'],
            $pasien->riwayatTindakLanjut()->whereNotNull('kategori')->orderBy('created_at')->pluck('kategori')->all());

        $data = $this->getJson(route('pasien.export'))->json();
        $this->assertSame('Meninggal', $data[0]['Kategori Tindak Lanjut']);
    }

    // ── Periode penelitian ─────────────────────────────────────────────

    public function test_periode_dan_histori_hanya_bulan_penelitian(): void
    {
        $this->penelitianMaretMei();
        $pasien = $this->pasien('PRB-001');
        foreach (['2026-02-10', '2026-03-12', '2026-05-11', '2026-06-01'] as $i => $tgl) {
            $pasien->riwayatPengambilanObat()->create([
                'no_faktur' => "F-{$i}", 'tanggal_pengambilan' => $tgl, 'jadwal_target' => $tgl,
                'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh',
            ]);
        }

        $this->assertSame(['2026-05', '2026-04', '2026-03'],
            array_column(app(KepatuhanService::class)->daftarPeriode(), 'value'));

        $histori = $this->getJson(route('pasien.show', $pasien))->json('riwayat_pengambilan_obat');
        $this->assertSame(['2026-05-11', '2026-03-12'], array_map(fn ($r) => substr($r['tanggal_pengambilan'], 0, 10), $histori));

        $export = $this->postJson(route('pasien.export.terpilih'), ['ids' => [$pasien->id]])->json('histori');
        $this->assertSame(['12/03/2026', '11/05/2026'], array_column($export, 'Tanggal Ambil'));

        $tren = app(KepatuhanService::class)->hitungTrenKepatuhan('2026-06-05');
        $this->assertSame(['2026-03', '2026-04', '2026-05'], array_column($tren, 'periode'));
    }

    // ── PRB Baru: pertama ambil di Mei tanpa tanggal mulai ─────────────

    public function test_tanpa_mulai_pertama_ambil_mei_adalah_prb_baru(): void
    {
        $this->penelitianMaretMei();

        $this->assertSame('Baru', KepatuhanService::tentukanJenisPrb(null, '2026-05-14', '2026-05-14'));
        $this->assertSame('Lama', KepatuhanService::tentukanJenisPrb(null, '2026-04-14', '2026-04-14'), 'April → Lama');
        $this->assertSame('Lama', KepatuhanService::tentukanJenisPrb(null, '2026-05-14', '2026-03-02'), 'punya riwayat Maret');
        $this->assertSame('Lama', KepatuhanService::tentukanJenisPrb('2026-01-05', '2026-05-14', '2026-05-14'), 'mulai Januari');
        $this->assertSame('Baru', KepatuhanService::tentukanJenisPrb('2026-05-02', '2026-05-14', '2026-05-14'), 'mulai Mei');

        config(['prb.periode_penelitian' => []]);
        $this->assertSame('Lama', KepatuhanService::tentukanJenisPrb(null, '2026-05-14', '2026-05-14'), 'tanpa periode penelitian');
    }

    public function test_hitung_ulang_jenis_mengubah_pasien_mei_tanpa_mulai(): void
    {
        $this->penelitianMaretMei();
        $mei = $this->pasien('PRB-001', ['tanggal_mulai_prb' => null, 'tanggal_pengambilan_terakhir' => '2026-05-14', 'tanggal_jadwal_berikutnya' => '2026-06-13']);
        $mei->riwayatPengambilanObat()->create(['no_faktur' => 'F-1', 'tanggal_pengambilan' => '2026-05-14', 'jadwal_target' => '2026-05-14', 'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh']);
        $belumAmbil = $this->pasien('PRB-002', ['jenis_prb' => 'Baru', 'jatah_obat' => 23]);

        $this->artisan('prb:hitung-ulang-jenis --dry-run')->assertSuccessful();
        $this->assertSame('Lama', $mei->fresh()->jenis_prb, 'dry-run tidak menyimpan');

        $this->artisan('prb:hitung-ulang-jenis')->assertSuccessful();
        $mei->refresh();
        $this->assertSame('Baru', $mei->jenis_prb);
        $this->assertSame(23, $mei->jatah_obat);
        $this->assertSame('2026-06-06', $mei->tanggal_jadwal_berikutnya->toDateString());
        $this->assertSame('Baru', $belumAmbil->fresh()->jenis_prb, 'pasien tanpa pengambilan tidak disentuh');
    }

    // ── Nama tersamar ──────────────────────────────────────────────────

    public function test_nama_disamarkan_saat_tambah_dan_import(): void
    {
        $this->assertSame('R***a D*i P***i', PasienPrb::samarkanNama('Rizka  Dwi Putri '));
        $this->assertSame('R***a D*i P***i', PasienPrb::samarkanNama('R***a D*i P***i'));
        $this->assertNull(PasienPrb::samarkanNama('  '));

        $this->post(route('pasien.store'), [
            'noka' => 'NAMA 1', 'nama_samaran' => 'Budi Santoso', 'fktp_asal' => 'Puskesmas Padasuka',
            'diagnosis_prb' => ['Asma'], 'tanggal_mulai_prb' => '2026-01-01', 'status' => 'Aktif',
        ])->assertSessionHasNoErrors();
        $this->assertSame('B**i S*****o', PasienPrb::where('noka', 'NAMA 1')->value('nama_samaran'));

        // Import: walau nama asli terkirim, yang tersimpan tetap tersamar
        $this->post(route('pasien.import'), ['data' => [[
            'noka' => 'NAMA 2', 'nama_samaran' => 'Siti Aminah', 'fktp_asal' => 'Puskesmas Padasuka',
            'diagnosis_prb' => ['Asma'], 'tanggal_mulai_prb' => '2026-01-05',
        ]]]);
        $this->assertSame('S**i A****h', PasienPrb::where('noka', 'NAMA 2')->value('nama_samaran'));
        $this->assertStringNotContainsString('Aminah', json_encode(\DB::table('pasien_prb')->get()));
    }

    // ── ID_PRB sebagai Kode Pasien ─────────────────────────────────────

    public function test_import_memakai_id_prb_sebagai_kode_pasien(): void
    {
        $baris = fn (string $kode, string $noka, string $ambil) => [
            'kode_pasien' => $kode, 'noka' => $noka, 'fktp_asal' => 'KPMA',
            'diagnosis_prb' => ['Hipertensi'], 'tanggal_mulai_prb' => '2025-01-27', 'tanggal_pengambilan' => $ambil,
        ];

        $this->post(route('pasien.import'), ['data' => [
            $baris('PRB001', '0001234567890', '2026-03-05'),
            $baris('PRB001', '0001234567890', '2026-04-04'), // pasien sama, pengambilan berikutnya
            $baris('PRB001', '0009999999999', '2026-04-10'), // ID_PRB sama, NOKA lain → ditolak
        ]])->assertSessionHas('importResult', ['baru' => 1, 'diperbarui' => 1, 'duplikat' => 0, 'invalid' => 1]);

        $pasien = PasienPrb::where('kode_pasien', 'PRB001')->firstOrFail();
        $this->assertSame('0001234567890', $pasien->noka);
        $this->assertSame(2, $pasien->riwayatPengambilanObat()->count());
        $this->assertSame(1, PasienPrb::count());
        $this->assertSame('Kode Pasien PRB001 sudah dipakai pasien dengan NOKA lain',
            \DB::table('resep_import')->where('status_import', 'invalid')->value('keterangan_import'));
    }

    // ── Filter diagnosis (ceklis, lebih dari satu) ─────────────────────

    public function test_filter_diagnosis_bisa_lebih_dari_satu(): void
    {
        $this->pasien('PRB-001', ['diagnosis_prb' => ['Hipertensi']]);
        $this->pasien('PRB-002', ['diagnosis_prb' => ['Asma']]);
        $this->pasien('PRB-003', ['diagnosis_prb' => ['Diabetes Melitus', 'Hipertensi']]);
        $this->pasien('PRB-004', ['diagnosis_prb' => ['PPOK']]);
        $this->pasien('PRB-005', ['diagnosis_prb' => ['Gout']]); // di luar 9 diagnosis baku

        $kode = function (string $query): array {
            $hasil = [];
            $this->get('/pasien?sort_by=kode_pasien&' . $query)->assertInertia(function (Assert $page) use (&$hasil) {
                $hasil = collect($page->toArray()['props']['pasienList'])->pluck('kode_pasien')->all();
            });
            return $hasil;
        };

        $this->assertSame(['PRB-001', 'PRB-002', 'PRB-003'], $kode('filter_diagnosis[]=Hipertensi&filter_diagnosis[]=Asma'));
        $this->assertSame(['PRB-002'], $kode('filter_diagnosis=Asma'), 'satu diagnosis (format lama) tetap bisa');
        $this->assertCount(5, $kode(''));

        $this->get('/pasien')->assertInertia(fn (Assert $page) => $page
            ->where('diagnosisOptions', fn ($opsi) => !collect($opsi)->contains('Gout') && collect($opsi)->contains('SLE')));

        $export = $this->getJson('/pasien-export?filter_diagnosis[]=PPOK&filter_diagnosis[]=Asma')->json();
        $this->assertSame(['PRB-002', 'PRB-004'], array_column($export, 'Kode Pasien'));
    }

    // ── Filter tindak lanjut (kategori terakhir) ───────────────────────

    public function test_filter_tindak_lanjut_memakai_kategori_terakhir(): void
    {
        $a = $this->pasien('PRB-001');
        $b = $this->pasien('PRB-002');
        $this->pasien('PRB-003'); // belum pernah ditindaklanjuti

        $catat = fn ($p, $jam, $kategori) => $p->riwayatTindakLanjut()->create([
            'tanggal_jam' => $jam, 'petugas' => 'QA', 'tindakan' => 'Belum mengambil obat', 'kategori' => $kategori,
        ]);
        $catat($a, '2026-06-01 09:00:00', 'Koordinasi FKTP');
        $catat($a, '2026-06-03 09:00:00', 'Kontak langsung'); // terakhir
        $catat($b, '2026-06-02 09:00:00', 'Koordinasi FKTP');

        $kode = function (string $query): array {
            $hasil = [];
            $this->get('/pasien?sort_by=kode_pasien&' . $query)->assertInertia(function (Assert $page) use (&$hasil) {
                $hasil = collect($page->toArray()['props']['pasienList'])->pluck('kode_pasien')->all();
            });
            return $hasil;
        };

        $this->assertSame(['PRB-002'], $kode('filter_tindak_lanjut=Koordinasi FKTP'));
        $this->assertSame(['PRB-001'], $kode('filter_tindak_lanjut=Kontak langsung'));
        $this->assertSame([], $kode('filter_tindak_lanjut=Lain-lain'));

        $export = $this->getJson('/pasien-export?filter_tindak_lanjut=Koordinasi FKTP')->json();
        $this->assertSame(['PRB-002'], array_column($export, 'Kode Pasien'));
    }

    public function test_filter_early_warning_di_export_cocok_dengan_tabel(): void
    {
        // Hari ini 05/06/2026
        $this->pasien('PRB-001', ['tanggal_jadwal_berikutnya' => '2026-05-20']); // overdue
        $this->pasien('PRB-002', ['tanggal_jadwal_berikutnya' => '2026-06-07']); // jadwal mendekat

        $export = $this->getJson('/pasien-export?tanggal_evaluasi=2026-06-05&filter_early_warning=Tidak Teratur')->json();
        $this->assertSame(['PRB-001'], array_column($export, 'Kode Pasien'));

        $export = $this->getJson('/pasien-export?tanggal_evaluasi=2026-06-05&filter_early_warning=Jadwal Mendekat')->json();
        $this->assertSame(['PRB-002'], array_column($export, 'Kode Pasien'));
    }

    // ── Satu pasien, dua diagnosis di baris berbeda ────────────────────

    public function test_import_noka_sama_diagnosis_beda_jadi_data_terpisah(): void
    {
        $baris = fn (string $diagnosis, string $mulai, string $ambil) => [
            'kode_pasien' => 'PRB001', 'noka' => '0001234567890', 'fktp_asal' => 'KPMA',
            'diagnosis_prb' => [$diagnosis], 'tanggal_mulai_prb' => $mulai, 'tanggal_pengambilan' => $ambil,
        ];

        $this->post(route('pasien.import'), ['data' => [
            $baris('Hipertensi', '2025-01-27', '2026-03-05'),
            $baris('Diabetes Melitus', '2026-05-02', '2026-05-10'),
            $baris('Hipertensi', '2025-01-27', '2026-04-04'),
        ]]);

        $this->assertSame(2, PasienPrb::count());
        $ht = PasienPrb::cariPerDiagnosis('0001234567890', ['Hipertensi']);
        $dm = PasienPrb::cariPerDiagnosis('0001234567890', ['Diabetes Melitus']);
        $this->assertSame('PRB001', $ht->kode_pasien);
        $this->assertSame('PRB001', $dm->kode_pasien); // ID_PRB sama → kode sama
        $this->assertSame(2, $ht->riwayatPengambilanObat()->count());
        $this->assertSame('2026-05-10', $dm->tanggal_pengambilan_terakhir->toDateString());
    }

    public function test_tambah_noka_sama_diagnosis_lain_jadi_data_baru(): void
    {
        $data = fn (array $diagnosis) => [
            'noka' => 'DUA 001', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => $diagnosis,
            'tanggal_mulai_prb' => '2025-01-27', 'status' => 'Aktif',
        ];

        $this->post(route('pasien.store'), $data(['Hipertensi']))->assertSessionHasNoErrors();
        $this->post(route('pasien.store'), $data(['Diabetes Melitus']))->assertSessionHasNoErrors();
        $this->post(route('pasien.store'), $data(['Hipertensi']))->assertSessionHasNoErrors(); // sama → tidak dobel

        $this->assertSame(2, PasienPrb::where('noka', 'DUA 001')->count());
    }

    public function test_sel_berisi_beberapa_diagnosis_dipecah(): void
    {
        $this->post(route('pasien.import'), ['data' => [[
            'kode_pasien' => 'PRB-1215', 'noka' => '57651873', 'fktp_asal' => 'KPMA',
            'diagnosis_prb' => ['Penyakit Jantung', 'Hipertensi'],
            'tanggal_mulai_prb' => '2026-05-05', 'tanggal_pengambilan' => '2026-05-05',
        ]]]);

        $this->assertSame(['PRB-1215', 'PRB-1215'], PasienPrb::orderBy('kode_pasien')->pluck('kode_pasien')->all());
        $this->assertSame([['Hipertensi'], ['Penyakit Jantung']],
            PasienPrb::orderBy('kunci_diagnosis')->pluck('diagnosis_prb')->all());
    }

    public function test_tambah_dua_diagnosis_jadi_dua_data_dan_ubah_hanya_satu(): void
    {
        $this->post(route('pasien.store'), [
            'noka' => 'TIGA 001', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => ['Hipertensi', 'Asma'],
            'tanggal_mulai_prb' => '2025-01-27', 'status' => 'Aktif',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, PasienPrb::where('noka', 'TIGA 001')->count());

        $ht = PasienPrb::cariPerDiagnosis('TIGA 001', ['Hipertensi']);
        $ubah = fn (array $diagnosis) => $this->put(route('pasien.update', $ht), [
            'noka' => 'TIGA 001', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => $diagnosis,
            'tanggal_mulai_prb' => '2025-01-27', 'status' => 'Aktif',
        ]);

        $ubah(['Hipertensi', 'PPOK'])->assertSessionHasErrors('diagnosis_prb'); // lebih dari satu
        $ubah(['Asma'])->assertSessionHasErrors('diagnosis_prb');                // bentrok dengan data Asma
        $ubah(['PPOK'])->assertSessionHasNoErrors();
        $this->assertSame(['PPOK'], $ht->fresh()->diagnosis_prb);
    }

    public function test_perintah_pecah_data_lama_per_diagnosis(): void
    {
        $lama = $this->pasien('PRB-0001', ['diagnosis_prb' => ['Hipertensi', 'Diabetes Melitus', 'Asma']]);
        $lama->riwayatPengambilanObat()->create([
            'tanggal_pengambilan' => '2026-03-05', 'jadwal_target' => '2026-03-05', 'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh',
        ]);

        $this->artisan('prb:pecah-diagnosis --dry-run')->assertSuccessful();
        $this->assertSame(1, PasienPrb::count());

        $this->artisan('prb:pecah-diagnosis')->assertSuccessful();
        $this->assertSame(['PRB-0001', 'PRB-0001', 'PRB-0001'], PasienPrb::orderBy('kode_pasien')->pluck('kode_pasien')->all());
        $this->assertSame(['Hipertensi'], $lama->fresh()->diagnosis_prb);
        foreach (PasienPrb::all() as $p) {
            $this->assertCount(1, $p->diagnosis_prb);
            $this->assertSame(1, $p->riwayatPengambilanObat()->count(), 'histori disalin');
        }
    }

    public function test_perintah_samakan_kode_berakhiran_ke_kode_asal(): void
    {
        $this->pasien('PRB-1215', ['noka' => '57651873', 'diagnosis_prb' => ['Penyakit Jantung']]);
        $this->pasien('PRB-1215-2', ['noka' => '57651873', 'diagnosis_prb' => ['Hipertensi']]);
        $this->pasien('PRB-1215-3', ['noka' => '57651873', 'diagnosis_prb' => ['Asma']]);
        $this->pasien('PRB-77-2', ['noka' => 'LAIN']);           // kode asal tidak ada → tetap
        $this->pasien('PRB-88', ['noka' => 'A']);
        $this->pasien('PRB-88-2', ['noka' => 'B']);              // NOKA beda → tetap

        $this->artisan('prb:samakan-kode --dry-run')->assertSuccessful();
        $this->assertSame(1, PasienPrb::where('kode_pasien', 'PRB-1215')->count());

        $this->artisan('prb:samakan-kode')->assertSuccessful();
        $this->assertSame(3, PasienPrb::where('kode_pasien', 'PRB-1215')->count());
        $this->assertTrue(PasienPrb::where('kode_pasien', 'PRB-77-2')->exists());
        $this->assertTrue(PasienPrb::where('kode_pasien', 'PRB-88-2')->exists());

        // Pencarian kode menampilkan semua diagnosisnya — tanggalnya sama → satu baris
        $this->get(route('pasien.index', ['search' => 'PRB-1215']))
            ->assertInertia(fn ($p) => $p->has('pasienList', 1)->has('pasienList.0.anggota_ids', 3)->has('pasienList.0.diagnosis_prb', 3));
    }

    // ── Tanggal evaluasi bawaan = akhir periode penelitian ─────────────

    public function test_tanggal_evaluasi_bawaan_akhir_periode_penelitian(): void
    {
        $this->penelitianMaretMei();

        Carbon::setTestNow('2026-11-10');
        $this->assertSame('2026-05-31', KepatuhanService::tanggalEvaluasiBawaan());
        $this->get('/pasien')->assertInertia(fn (Assert $page) => $page
            ->where('tanggalEvaluasi', '2026-05-31')
            ->where('tanggalEvaluasiBawaan', '2026-05-31'));

        // Masih di tengah periode → hari ini
        Carbon::setTestNow('2026-04-15');
        $this->assertSame('2026-04-15', KepatuhanService::tanggalEvaluasiBawaan());

        // Periode tidak dibatasi → hari ini
        Carbon::setTestNow('2026-11-10');
        config(['prb.periode_penelitian' => []]);
        $this->assertSame('2026-11-10', KepatuhanService::tanggalEvaluasiBawaan());
    }

    // ── Prioritas Early Warning ────────────────────────────────────────

    public function test_urutan_bawaan_prioritas_early_warning(): void
    {
        // Hari ini 05/06/2026
        $this->pasien('PRB-A', ['tanggal_jadwal_berikutnya' => '2026-06-30']); // belum memasuki jadwal
        $this->pasien('PRB-B', ['tanggal_jadwal_berikutnya' => '2026-06-07']); // jadwal mendekat (H-2)
        $this->pasien('PRB-C', ['tanggal_jadwal_berikutnya' => '2026-06-03']); // masih toleransi (H+2)
        $this->pasien('PRB-D', ['tanggal_jadwal_berikutnya' => '2026-06-05']); // jatuh tempo
        $this->pasien('PRB-E', ['tanggal_jadwal_berikutnya' => '2026-05-20']); // overdue
        $this->pasien('PRB-F', ['status' => 'Meninggal']);
        $this->pasien('PRB-G', ['tanggal_jadwal_berikutnya' => '2026-06-06']); // jadwal mendekat (H-1)

        $this->get('/pasien')->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort_by', 'prioritas_ews')
            ->where('pasienList', fn ($rows) => collect($rows)->pluck('kode_pasien')->all()
                === ['PRB-C', 'PRB-D', 'PRB-G', 'PRB-B', 'PRB-E', 'PRB-A', 'PRB-F'])
        );
    }
}
