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
 * Pasien dengan beberapa diagnosis:
 * - tanggal mulai PRB & tanggal ambil sama → satu baris berisi beberapa diagnosis;
 *   tanggal berbeda → baris terpisah;
 * - semua jumlah (Total, Patuh, Tidak Teratur) dihitung per pasien: 3 diagnosis = 1.
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

    private function buat(string $kode, string $noka, string $diagnosis, string $fktp, string $mulai = '2026-01-01'): PasienPrb
    {
        return PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => $noka, 'fktp_asal' => $fktp,
            'diagnosis_prb' => [$diagnosis], 'tanggal_mulai_prb' => $mulai,
            'tanggal_jadwal_berikutnya' => '2026-09-01', 'status' => 'Aktif',
        ]);
    }

    public function test_jumlah_pasien_unik(): void
    {
        $this->assertSame(2, PasienPrb::jumlahPasienUnik(['0001 112', '0001112', ' 0001112 ', 'abc', 'ABC']));
        $this->assertSame(3, PasienPrb::jumlahPasienUnik(['X1', '', null]), 'tanpa NOKA dihitung per data');
        $this->assertSame(2, PasienPrb::jumlahPasienUnik(PasienPrb::all()));
    }

    public function test_status_pasien_mendahulukan_tidak_teratur(): void
    {
        $this->assertSame('Tidak Teratur', KepatuhanService::statusPasien(['Patuh', 'Tidak Teratur', 'Patuh']));
        $this->assertSame('Patuh', KepatuhanService::statusPasien(['Dalam Toleransi', 'Patuh']));
        $this->assertSame('Dalam Toleransi', KepatuhanService::statusPasien(['Dalam Toleransi']));
    }

    public function test_dashboard_dihitung_per_pasien(): void
    {
        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('statistik.total_pasien', 2)
            ->where('statistik.total_diagnosis', 3)
            ->where('statistik.total_aktif', 2)
            ->where('statistik.tidak_patuh', 2) // 2 diagnosis satu pasien = 1
        );
    }

    public function test_rekap_dihitung_per_pasien(): void
    {
        $this->get(route('rekap.index'))->assertInertia(fn (Assert $p) => $p
            ->where('summary.total', 2)
            ->where('summary.tidak_patuh', 2)
            ->has('rekapFktp', 2)
            ->where('rekapFktp', fn ($rows) => collect($rows)->firstWhere('fktp', 'Puskesmas Padasuka')['total'] === 1
                && collect($rows)->firstWhere('fktp', 'Puskesmas Padasuka')['tidak_patuh'] === 1
                && collect($rows)->firstWhere('fktp', 'Puskesmas Griya Antapani')['total'] === 1)
        );
    }

    public function test_tanggal_sama_satu_baris_tanggal_beda_baris_terpisah(): void
    {
        $this->get(route('pasien.index'))->assertInertia(fn (Assert $p) => $p
            ->has('pasienList', 2)
            ->where('pasienList.0.kode_pasien', 'PRB-1')
            ->where('pasienList.0.diagnosis_prb', ['Diabetes Melitus', 'Hipertensi'])
            ->has('pasienList.0.anggota_ids', 2)
        );

        // Pasien C: dua diagnosis dengan tanggal mulai berbeda → dua baris
        $this->buat('PRB-3', '0009990001112', 'Asma', 'Puskesmas Padasuka', '2025-01-01');
        $this->buat('PRB-3', '0009990001112', 'PPOK', 'Puskesmas Padasuka', '2025-06-01');
        $this->get(route('pasien.index', ['search' => 'PRB-3']))
            ->assertInertia(fn (Assert $p) => $p->has('pasienList', 2));

        // Pasien A: satu diagnosis mengambil obat → tanggal berbeda → baris terpisah
        app(KepatuhanService::class)->prosesRealisasi(PasienPrb::where('kode_pasien', 'PRB-1')->where('kunci_diagnosis', 'hipertensi')->first(), '2026-09-02');
        $this->get(route('pasien.index', ['search' => 'PRB-1']))
            ->assertInertia(fn (Assert $p) => $p->has('pasienList', 2));
    }

    public function test_urut_per_kolom_naik_dan_turun(): void
    {
        $urutan = fn (array $q) => collect($this->get(route('pasien.index', $q))->viewData('page')['props']['pasienList'])
            ->pluck('kode_pasien')->all();

        $this->assertSame(['PRB-1', 'PRB-2'], $urutan(['sort_by' => 'kode_pasien', 'sort_order' => 'asc']));
        $this->assertSame(['PRB-2', 'PRB-1'], $urutan(['sort_by' => 'kode_pasien', 'sort_order' => 'desc']));
        // Diagnosis: "Asma" < "Diabetes Melitus, Hipertensi"
        $this->assertSame(['PRB-2', 'PRB-1'], $urutan(['sort_by' => 'diagnosis_prb', 'sort_order' => 'asc']));
        $this->assertSame(['PRB-1', 'PRB-2'], $urutan(['sort_by' => 'diagnosis_prb', 'sort_order' => 'desc']));
        // Early Warning: turun = kebalikan urutan naik
        $naik = $urutan(['sort_by' => 'prioritas_ews', 'sort_order' => 'asc']);
        $this->assertSame(array_reverse($naik), $urutan(['sort_by' => 'prioritas_ews', 'sort_order' => 'desc']));
    }

    public function test_aksi_baris_gabungan_berlaku_untuk_semua_diagnosis(): void
    {
        $ht = PasienPrb::where('kunci_diagnosis', 'hipertensi')->first();

        // Realisasi dari baris gabungan → tercatat di kedua diagnosis, tetap satu baris
        $this->post(route('pasien.realisasi', $ht), ['tanggal_realisasi' => '2026-09-02'])->assertSessionHas('success');
        $this->assertSame([1, 1], PasienPrb::where('noka', '0001112223334')->get()->map(fn ($p) => $p->riwayatPengambilanObat()->count())->all());
        $this->get(route('pasien.index', ['search' => 'PRB-1']))->assertInertia(fn (Assert $p) => $p->has('pasienList', 1));

        // Tindak lanjut & status → kedua diagnosis
        $this->post(route('tindak-lanjut.store', $ht), ['tindakan' => 'Belum mengambil obat', 'kategori' => 'Kontak langsung'])->assertSessionHas('success');
        $this->assertSame(2, \App\Models\RiwayatTindakLanjut::count());
        $this->post(route('pasien.status', $ht), ['status' => 'Pasif', 'alasan_pasif' => 'Pindah Faskes']);
        $this->assertSame(['Pasif', 'Pasif'], PasienPrb::where('noka', '0001112223334')->pluck('status')->all());

        // Detail: semua diagnosis di baris itu
        $this->getJson(route('pasien.show', $ht))
            ->assertJsonPath('diagnosis_kelompok', ['Diabetes Melitus', 'Hipertensi'])
            ->assertJsonCount(2, 'anggota_ids');

        // Hapus baris → kedua diagnosis terhapus
        $this->delete(route('pasien.destroy', $ht));
        $this->assertSame(0, PasienPrb::where('noka', '0001112223334')->count());
        $this->assertSame(1, PasienPrb::count());
    }
}
