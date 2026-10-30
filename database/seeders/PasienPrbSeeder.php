<?php

namespace Database\Seeders;

use App\Models\PasienPrb;
use App\Services\KepatuhanService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class PasienPrbSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        $service = new KepatuhanService();

        $fktpList = [
            'Puskesmas Kecamatan Kebayoran Baru',
            'Puskesmas Kecamatan Tebet',
            'Klinik Pratama Sehat Selalu',
            'Klinik Medika Utama',
            'Puskesmas Kelurahan Senayan'
        ];

        // Buat 30 pasien
        for ($i = 1; $i <= 30; $i++) {
            $status = $faker->randomElement(['Aktif', 'Aktif', 'Aktif', 'Aktif', 'Pasif']); // 80% Aktif
            $alasanPasif = $status === 'Pasif' ? $faker->randomElement(PasienPrb::ALASAN_PASIF_OPTIONS) : null;
            
            // Tanggal mulai PRB acak antara 1 tahun lalu hingga 1 bulan lalu
            $tanggalMulai = Carbon::now()->subDays(rand(30, 365));
            
            // Determine jenis_prb and jatah_obat
            $isBaru = $faker->boolean(20); // 20% Pasien Baru
            $jenisPrb = $isBaru ? 'Baru' : 'Lama';
            $jatahObat = $isBaru ? 23 : 30;

            // Generate data pasien
            $pasien = PasienPrb::create([
                'kode_pasien' => 'PRB-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                // NOKA tersandi dummy — nama & No. HP tidak disimpan (etik)
                'noka' => 'PRB KPMA ' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'fktp_asal' => $faker->randomElement($fktpList),
                'diagnosis_prb' => $faker->randomElements(PasienPrb::DIAGNOSIS_OPTIONS, rand(1, 3)),
                'tanggal_mulai_prb' => $tanggalMulai->toDateString(),
                'jatah_obat' => $jatahObat,
                'status' => $status,
                'alasan_pasif' => $alasanPasif,
                'sumber_data' => 'Seeder',
                'jenis_prb' => $jenisPrb,
            ]);

            // Jika pasien aktif, buat skenario riwayat
            if ($status === 'Aktif') {
                $skenario = $faker->randomElement([
                    'patuh', // Pengambilan terakhir bulan lalu, jadwal target masih bulan ini (belum/sudah diambil tepat waktu)
                    'terlambat_toleransi', // Jadwal target lewat 1-3 hari
                    'terlambat_jauh', // Jadwal target lewat > 3 hari
                ]);

                $hariIni = Carbon::today();
                
                switch ($skenario) {
                    case 'patuh':
                        // Ambil 28 hari lalu, jadwalnya hari ini + 2 (Belum jatuh tempo / masih H-2)
                        $tglTerakhir = $hariIni->copy()->subDays(28);
                        break;
                    case 'terlambat_toleransi':
                        // Ambil 32 hari lalu, jadwalnya hari ini - 2 (Lewat 2 hari, masih dalam H+3)
                        $tglTerakhir = $hariIni->copy()->subDays(32);
                        break;
                    case 'terlambat_jauh':
                        // Ambil 45 hari lalu, jadwalnya hari ini - 15 (Lewat 15 hari, overdue > H+3)
                        $tglTerakhir = $hariIni->copy()->subDays(45);
                        break;
                }

                // Siklus 1: Sejarah lama yang patuh
                $tglSiklusLama = $tglTerakhir->copy()->subDays(30);
                $service->prosesRealisasi($pasien, $tglSiklusLama->toDateString());
                
                // Siklus 2: Siklus terakhir yang menentukan status hari ini
                $service->prosesRealisasi($pasien, $tglTerakhir->toDateString());

                // Tambahkan random riwayat tindak lanjut jika terlambat jauh
                if ($skenario === 'terlambat_jauh') {
                    $pasien->riwayatTindakLanjut()->create([
                        'tanggal_jam' => $hariIni->copy()->subDays(5),
                        'petugas' => 'Apoteker Rini',
                        'tindakan' => 'Tidak dapat dihubungi',
                        'catatan' => 'Ditelepon 2x tidak diangkat, dikirim WA hanya centang 1',
                        'update_realisasi_otomatis' => false
                    ]);
                }
            }
        }
    }
}
