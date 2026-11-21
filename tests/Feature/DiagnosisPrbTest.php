<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use App\Models\ResepImport;
use App\Models\RiwayatPengambilanObat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hanya 9 diagnosis PRB yang masuk penelitian. */
class DiagnosisPrbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function baris(array $diagnosis, string $faktur): array
    {
        return [
            'no_faktur' => $faktur, 'noka' => '0001112223334', 'fktp_asal' => 'KPMA',
            'diagnosis_prb' => $diagnosis, 'tanggal_mulai_prb' => '2025-01-01', 'tanggal_pengambilan' => '2026-03-05',
        ];
    }

    public function test_bakukan_diagnosis(): void
    {
        $this->assertSame('Diabetes Melitus', PasienPrb::bakukanDiagnosis('dm'));
        $this->assertSame('Hipertensi', PasienPrb::bakukanDiagnosis(' HT '));
        $this->assertSame('Asma', PasienPrb::bakukanDiagnosis('ASMA'));
        $this->assertSame('Penyakit Jantung', PasienPrb::bakukanDiagnosis('penyakit  jantung'));
        $this->assertNull(PasienPrb::bakukanDiagnosis('HY'));
        $this->assertNull(PasienPrb::bakukanDiagnosis('Lainnya'));
    }

    /** Pemetaan notulensi meeting 15 November 2026. */
    public function test_pemetaan_diagnosis_penyerta(): void
    {
        foreach ([
            'CHF' => 'Penyakit Jantung', 'DLP' => 'Penyakit Jantung', 'aritmia' => 'Penyakit Jantung',
            'Neuropati' => 'Diabetes Melitus', 'GOUT' => 'Diabetes Melitus', 'hiperurisemia' => 'Diabetes Melitus',
            'asam urat' => 'Diabetes Melitus', 'AU' => 'Diabetes Melitus',
            'BPH' => 'Hipertensi',
            'Psikiatri' => 'Skizofrenia', 'Parkinson' => 'Skizofrenia', 'Schizophrenia' => 'Skizofrenia',
            'Anemia' => 'Asma',
        ] as $awal => $baku) {
            $this->assertSame($baku, PasienPrb::bakukanDiagnosis($awal), $awal);
        }
    }

    public function test_import_diagnosis_di_luar_9_tidak_valid(): void
    {
        $this->post(route('pasien.import'), ['data' => [
            $this->baris(['HY'], 'F-1'),
            $this->baris(['Hipertensi', 'XYZ'], 'F-2'), // sel campuran: HT masuk, XYZ tidak valid
            $this->baris(['dm'], 'F-3'),                 // singkatan dibakukan
            $this->baris(['Hipertensi', 'BPH'], 'F-4'),  // BPH → Hipertensi: satu data saja
            $this->baris(['CHF'], 'F-5'),                // CHF → Penyakit Jantung
        ]])->assertSessionHas('importResult', ['baru' => 3, 'diperbarui' => 1, 'duplikat' => 0, 'invalid' => 2]);

        $this->assertSame([['Diabetes Melitus'], ['Hipertensi'], ['Penyakit Jantung']],
            PasienPrb::orderBy('kunci_diagnosis')->pluck('diagnosis_prb')->all());
        $this->assertSame(4, RiwayatPengambilanObat::count());
        $this->assertSame(
            ['Diagnosis HY bukan salah satu dari 9 diagnosis PRB', 'Diagnosis XYZ bukan salah satu dari 9 diagnosis PRB'],
            ResepImport::where('status_import', 'invalid')->orderBy('keterangan_import')->pluck('keterangan_import')->all()
        );
    }

    public function test_form_menolak_diagnosis_di_luar_9(): void
    {
        $this->post(route('pasien.store'), [
            'noka' => 'X 001', 'fktp_asal' => 'KPMA', 'diagnosis_prb' => ['Lainnya'],
            'tanggal_mulai_prb' => '2025-01-01', 'status' => 'Aktif',
        ])->assertSessionHasErrors('diagnosis_prb.0');

        $this->assertSame(0, PasienPrb::count());
    }

    public function test_filter_hanya_9_diagnosis(): void
    {
        PasienPrb::create(['kode_pasien' => 'PRB-1', 'noka' => '1', 'fktp_asal' => 'X', 'diagnosis_prb' => ['GOUT'], 'status' => 'Aktif']);

        $this->get(route('pasien.index'))
            ->assertInertia(fn ($p) => $p->where('diagnosisOptions', PasienPrb::DIAGNOSIS_OPTIONS));
    }

    public function test_perintah_hapus_diagnosis_non_prb(): void
    {
        $buat = fn (string $kode, string $d) => PasienPrb::create([
            'kode_pasien' => $kode, 'noka' => "N-{$kode}", 'fktp_asal' => 'X', 'diagnosis_prb' => [$d], 'status' => 'Aktif',
        ]);
        $buat('PRB-1', 'Hipertensi');
        $gout = $buat('PRB-2', 'HY');
        $buat('PRB-3', 'XYZ');
        $buat('PRB-4', 'CHF'); // bisa dipetakan → tidak dihapus
        $gout->riwayatPengambilanObat()->create([
            'tanggal_pengambilan' => '2026-03-05', 'jadwal_target' => '2026-03-05', 'selisih_hari' => 0, 'status_kepatuhan' => 'Patuh',
        ]);

        $this->artisan('prb:hapus-diagnosis-non-prb --dry-run')->assertSuccessful();
        $this->assertSame(4, PasienPrb::count());

        $this->artisan('prb:hapus-diagnosis-non-prb')->assertSuccessful();
        $this->assertSame(['CHF', 'Hipertensi'], PasienPrb::pluck('diagnosis_prb')->flatten()->sort()->values()->all());
        $this->assertSame(0, RiwayatPengambilanObat::count(), 'histori ikut terhapus');
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'hapus_massal']);
    }
}
