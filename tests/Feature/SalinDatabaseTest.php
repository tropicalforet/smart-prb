<?php

namespace Tests\Feature;

use App\Models\PasienPrb;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class SalinDatabaseTest extends TestCase
{
    use DatabaseMigrations; // tanpa transaksi: VACUUM tidak boleh di dalam transaksi

    public function test_salinan_berisi_data_tanpa_sesi(): void
    {
        PasienPrb::create([
            'kode_pasien' => 'PRB-001', 'noka' => '0001', 'fktp_asal' => 'X',
            'diagnosis_prb' => ['Asma'], 'status' => 'Aktif',
        ]);
        DB::table('sessions')->insert([
            'id' => 'abc', 'payload' => 'x', 'last_activity' => time(),
        ]);

        $tujuan = storage_path('framework/testing/salinan-' . uniqid() . '.sqlite');

        try {
            $this->artisan('prb:salin-database', ['tujuan' => $tujuan])->assertSuccessful();
            $this->artisan('prb:salin-database', ['tujuan' => $tujuan])->assertFailed(); // tidak menimpa tanpa --force

            $salinan = new PDO('sqlite:' . $tujuan);
            $this->assertSame(1, (int) $salinan->query('select count(*) from pasien_prb')->fetchColumn());
            $this->assertSame(0, (int) $salinan->query('select count(*) from sessions')->fetchColumn());
            $salinan = null;
        } finally {
            @unlink($tujuan);
        }
    }
}
