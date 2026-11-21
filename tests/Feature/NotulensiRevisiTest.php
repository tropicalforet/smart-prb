<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Models\RiwayatPengingatWa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Revisi dari notulensi meeting 15 November 2026. */
class NotulensiRevisiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Petugas A']));
    }

    private function baris(string $noka, string $diagnosis, ?string $ambil, array $extra = []): array
    {
        return array_filter([
            'noka' => $noka, 'fktp_asal' => 'KPMA', 'diagnosis_prb' => [$diagnosis],
            'tanggal_mulai_prb' => '2025-01-01', 'tanggal_pengambilan' => $ambil,
        ] + $extra, fn ($v) => $v !== null);
    }

    public function test_ringkasan_impor_per_pasien_dan_baris_gagal(): void
    {
        $this->postJson(route('pasien.import'), ['data' => [
            $this->baris('N1', 'Hipertensi', '2026-03-05', ['baris_excel' => 'S#2#hipertensi']),
            $this->baris('N1', 'Hipertensi', '2026-04-05', ['baris_excel' => 'S#2#hipertensi']),
            $this->baris('N1', 'Diabetes Melitus', '2026-03-05', ['baris_excel' => 'S#2#diabetes melitus']),
            $this->baris('N2', 'Asma', '2026-03-07', ['baris_excel' => 'S#3#asma']),
            $this->baris('N3', 'HY', '2026-03-07', ['baris_excel' => 'S#4#hy']),
        ]])->assertOk()->assertJson([
            'baru' => 3, 'diperbarui' => 1, 'invalid' => 1,
            'pasien' => ['N1' => ['Hipertensi', 'Diabetes Melitus'], 'N2' => ['Asma']],
            'baris_gagal' => ['S#4#hy'],
        ]);
    }

    public function test_baris_tidak_valid_masuk_tabel_buffer(): void
    {
        $this->postJson(route('pasien.import.tidak-valid'), ['data' => [
            ['noka' => 'N9', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => ['Hipertensi'], 'tanggal_pengambilan' => 'bukan tanggal',
             'keterangan' => 'Sheet1 baris 9: FKTP / Faskes Asal wajib diisi'],
            ['keterangan' => 'Sheet1 baris 10: NOKA / No. BPJS wajib diisi'],
        ]])->assertOk()->assertJson(['invalid' => 2]);

        $this->assertSame(2, ResepImport::where('status_import', 'invalid')->count());
        $this->assertSame(0, PasienPrb::count());
        $this->assertNull(ResepImport::where('noka', 'N9')->value('tanggal_pengambilan'));
    }

    public function test_faktur_sama_beda_bulan_tidak_menimpa_histori(): void
    {
        $this->postJson(route('pasien.import'), ['data' => [$this->baris('N1', 'Hipertensi', '2026-03-31', ['no_faktur' => 'F-1'])]]);
        $this->postJson(route('pasien.import'), ['data' => [$this->baris('N1', 'Hipertensi', '2026-05-01', ['no_faktur' => 'F-1'])]])
            ->assertJson(['diperbarui' => 1]);

        $this->assertSame(['2026-03-31', '2026-05-01'],
            RiwayatPengambilanObat::orderBy('tanggal_pengambilan')->get()->map(fn ($r) => $r->tanggal_pengambilan->toDateString())->all());

        // Impor ulang → duplikat, tidak bertambah
        $this->postJson(route('pasien.import'), ['data' => [$this->baris('N1', 'Hipertensi', '2026-05-01', ['no_faktur' => 'F-1'])]])
            ->assertJson(['duplikat' => 1]);
        $this->assertSame(2, RiwayatPengambilanObat::count());

        // Bulan yang sama → koreksi tanggal (riwayat diperbarui, tidak bertambah)
        $this->postJson(route('pasien.import'), ['data' => [$this->baris('N1', 'Hipertensi', '2026-03-30', ['no_faktur' => 'F-1'])]]);
        $this->assertSame(['2026-03-30', '2026-05-01'],
            RiwayatPengambilanObat::orderBy('tanggal_pengambilan')->get()->map(fn ($r) => $r->tanggal_pengambilan->toDateString())->all());
    }

    public function test_filter_pasien_lebih_dari_satu_diagnosis(): void
    {
        $buat = fn ($kode, $noka, $d) => PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => $noka, 'fktp_asal' => 'KPMA', 'diagnosis_prb' => [$d],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif',
        ]);
        $buat('PRB-1', 'N1', 'Hipertensi');
        $buat('PRB-1', 'N1', 'Diabetes Melitus');
        $buat('PRB-2', 'N2', 'Asma');

        $this->get(route('pasien.index', ['filter_jumlah_diagnosis' => 'lebih_dari_satu']))
            ->assertInertia(fn (Assert $p) => $p->has('pasienList', 2)->where('pasienList.0.noka', 'N1')->where('pasienList.1.noka', 'N1'));
        $this->get(route('pasien.index', ['filter_jumlah_diagnosis' => 'satu']))
            ->assertInertia(fn (Assert $p) => $p->has('pasienList', 1)->where('pasienList.0.noka', 'N2'));
    }

    public function test_perintah_petakan_diagnosis_ganti_dan_gabung(): void
    {
        $buat = fn ($id, $kode, $noka, $d, $tanggal) => tap(PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => $noka, 'fktp_asal' => 'KPMA', 'diagnosis_prb' => [$d],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif',
        ]), fn ($p) => collect($tanggal)->each(fn ($t) => $p->riwayatPengambilanObat()->create([
            'no_faktur' => "{$id}-{$t}", 'tanggal_pengambilan' => $t, 'jadwal_target' => $t, 'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh',
        ])));

        $dm  = $buat('a', 'PRB-1', 'N1', 'Diabetes Melitus', ['2026-03-05', '2026-04-05']);
        $gout = $buat('b', 'PRB-1', 'N1', 'GOUT', ['2026-03-05', '2026-04-05']); // → DM, sudah ada → gabung
        $gout->riwayatTindakLanjut()->create(['tanggal_jam' => now(), 'petugas' => 'X', 'tindakan' => 'Belum mengambil obat']);
        $buat('c', 'PRB-1', 'N1', 'BPH', ['2026-03-05']);                          // → HT, belum ada → diganti
        $buat('d', 'PRB-2', 'N2', 'CHF', ['2026-03-07']);                          // → Penyakit Jantung
        $buat('e', 'PRB-3', 'N3', 'HY', ['2026-03-07']);                           // tidak dipetakan
        $buat('f', 'PRB-4', 'N4', 'Asma', ['2026-03-07']);
        $buat('g', 'PRB-4', 'N4', 'Anemia', ['2026-03-07', '2026-04-07']);        // tanggal baru → dilewati

        $this->artisan('prb:petakan-diagnosis --dry-run')->assertSuccessful();
        $this->assertSame(7, PasienPrb::count());

        $this->artisan('prb:petakan-diagnosis')->assertSuccessful();

        $this->assertSame(
            ['N1' => ['Diabetes Melitus', 'Hipertensi'], 'N2' => ['Penyakit Jantung'], 'N3' => ['HY'], 'N4' => ['Anemia', 'Asma']],
            PasienPrb::all()->groupBy('noka')->map(fn ($g) => $g->pluck('diagnosis_prb')->flatten()->sort()->values()->all())->sortKeys()->all()
        );
        $this->assertSame(1, $dm->riwayatTindakLanjut()->count(), 'tindak lanjut pindah ke data tujuan');
        $this->assertSame(2, $dm->riwayatPengambilanObat()->count());
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'petakan_diagnosis']);
    }

    public function test_histori_pengiriman_whatsapp(): void
    {
        $pasien = PasienPrb::create([
            'kode_pasien' => 'PRB-1', 'noka' => 'N1', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => ['Hipertensi'],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif', 'no_whatsapp' => '6281234567890',
        ]);

        $this->postJson(route('pengingat-wa.store', $pasien), ['tahap' => 'Terlambat', 'jadwal_target' => '2026-05-28'])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.petugas', 'Petugas A')->assertJsonPath('0.tahap', 'Terlambat');

        $this->assertSame(1, RiwayatPengingatWa::count());
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'kirim_pengingat_wa']);
        $this->getJson(route('pasien.show', $pasien))->assertJsonCount(1, 'riwayat_pengingat_wa');

        $pasien->delete();
        $this->assertSame(0, RiwayatPengingatWa::count(), 'histori ikut terhapus bersama pasien');
    }
}
