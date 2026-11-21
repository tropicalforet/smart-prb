<?php

namespace Database\Seeders;

use App\Http\Controllers\PasienPrbController;
use App\Models\PasienPrb;
use App\Models\RiwayatTindakLanjut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Data DEMO (fiktif) untuk server yang dibuka dosen/penguji.
 *
 *   php artisan db:seed --class=DemoDataSeeder --force
 *
 * Semua NOKA, nama, dan FKTP fiktif (NOKA berawalan 999). Data dimasukkan
 * lewat proses import yang sama dengan file Excel apotek, satu kali per
 * bulan (Maret, April, Mei 2026), sehingga jadwal, status kepatuhan, dan
 * Early Warning dihitung oleh logika aplikasi yang sebenarnya.
 *
 * Hanya berjalan pada database yang belum berisi data pasien, agar tidak
 * pernah tercampur dengan data penelitian asli.
 */
class DemoDataSeeder extends Seeder
{
    private const JUMLAH_PASIEN = 60;

    // FKTP harus salah satu dari 8 FKTP penelitian (impor menolak FKTP lain)
    private const FKTP = [
        'Puskesmas Babakan Sari',
        'Puskesmas Griya Antapani',
        'Puskesmas Padasuka',
        'Klinik Pratama Medika Antapani',
        'Klinik Pratama Seno Medika',
        'Puskesmas Ibrahim Adjie',
    ];

    // Nama fiktif (Faker tidak terpasang di server production)
    private const NAMA_DEPAN = [
        'Ahmad', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fitri', 'Gilang', 'Hana', 'Indra', 'Joko',
        'Kartini', 'Lestari', 'Maya', 'Nur', 'Oki', 'Putri', 'Rina', 'Sari', 'Taufik', 'Wati',
        'Yanti', 'Agus', 'Bambang', 'Siti', 'Hendra', 'Ratna', 'Dian', 'Asep', 'Euis', 'Ujang',
    ];
    private const NAMA_BELAKANG = [
        'Saputra', 'Wijaya', 'Lestari', 'Hidayat', 'Kusuma', 'Pratama', 'Rahayu', 'Santoso',
        'Permana', 'Gunawan', 'Nugraha', 'Suryani', 'Hakim', 'Setiawan', 'Maharani', 'Firmansyah',
    ];

    public function run(): void
    {
        if (PasienPrb::exists()) {
            $this->command->error('[DemoDataSeeder] Database sudah berisi data pasien — data demo tidak ditambahkan.');
            return;
        }

        $admin = User::first();
        if (!$admin) {
            $this->command->error('[DemoDataSeeder] Buat akun admin dulu: php artisan db:seed --class=AdminUserSeeder --force');
            return;
        }
        Auth::setUser($admin);

        mt_srand(2026);

        $perBulan = ['2026-03' => [], '2026-04' => [], '2026-05' => []];
        $semuaPasien = [];

        for ($i = 1; $i <= self::JUMLAH_PASIEN; $i++) {
            $kode = sprintf('PRB-%04d', $i);
            $noka = '999' . str_pad((string) (7000000 + $i * 137), 10, '0', STR_PAD_LEFT);
            $nama = self::NAMA_DEPAN[array_rand(self::NAMA_DEPAN)] . ' ' . self::NAMA_BELAKANG[array_rand(self::NAMA_BELAKANG)];
            $fktp = self::FKTP[array_rand(self::FKTP)];

            // ±1 dari 8 pasien punya dua diagnosis → dua data PRB, kode sama
            $diagnosis = (array) array_rand(array_flip(PasienPrb::DIAGNOSIS_OPTIONS), $i % 8 === 0 ? 2 : 1);

            // ±15% PRB Baru (mulai Mei 2026), sisanya PRB Lama
            $baru = $i % 7 === 0;
            $mulai = $baru
                ? Carbon::create(2026, 5, mt_rand(1, 6))
                : Carbon::create(2024, 6, 1)->addDays(mt_rand(0, 600));

            foreach ($diagnosis as $d) {
                $identitas = [
                    'kode_pasien'       => $kode,
                    'noka'              => $noka,
                    'nama_samaran'      => $nama,
                    'fktp_asal'         => $fktp,
                    'diagnosis_prb'     => [$d],
                    'tanggal_mulai_prb' => $mulai->toDateString(),
                ];
                $semuaPasien[] = [$noka, [$d]];

                foreach ($this->jadwalAmbil($baru, $mulai) as $tanggal) {
                    $perBulan[$tanggal->format('Y-m')][] = $identitas + [
                        'no_faktur'           => sprintf('DEMO-%s-%s-%s', $tanggal->format('ymd'), $kode, substr(md5($d), 0, 4)),
                        'tanggal_berobat'     => $tanggal->toDateString(),
                        'tanggal_pengambilan' => $tanggal->toDateString(),
                    ];
                }
            }
        }

        // Import per bulan, persis seperti petugas mengunggah file apotek tiap bulan
        $controller = app(PasienPrbController::class);
        foreach ($perBulan as $bulan => $baris) {
            if (!$baris) {
                continue;
            }
            usort($baris, fn ($a, $b) => strcmp($a['tanggal_pengambilan'], $b['tanggal_pengambilan']));
            $request = Request::create('/pasien/import', 'POST', ['data' => $baris]);
            $request->setUserResolver(fn () => $admin);
            app()->call([$controller, 'import'], ['request' => $request]);
            $this->command->info("Import demo {$bulan}: " . count($baris) . ' baris.');
        }

        $this->statusDanTindakLanjut($semuaPasien, $admin->name);

        $this->command->info('Data demo selesai: ' . PasienPrb::count() . ' data PRB, '
            . PasienPrb::distinct()->count('noka') . ' pasien.');
    }

    /**
     * Tanggal ambil obat Maret–Mei 2026 dengan pola yang bervariasi:
     * tepat waktu, terlambat, dan tidak datang di bulan terakhir.
     *
     * @return Carbon[]
     */
    private function jadwalAmbil(bool $baru, Carbon $mulai): array
    {
        if ($baru) {
            // PRB Baru: obat pertama dari FKRTL saat mulai PRB, pengambilan
            // pertama di apotek dijadwalkan mulai + 23 hari (kadang terlambat),
            // tetap di bulan Mei agar terhitung sebagai PRB Baru.
            $akhirMei = Carbon::create(2026, 5, 31);
            $tanggal  = $mulai->copy()->addDays(23 + (mt_rand(1, 10) <= 6 ? mt_rand(-2, 2) : mt_rand(5, 8)));

            return [$tanggal->greaterThan($akhirMei) ? $akhirMei : $tanggal];
        }

        $pola = mt_rand(1, 10); // 1–6 patuh, 7–8 kadang terlambat, 9–10 berhenti datang
        $tanggal = Carbon::create(2026, 3, mt_rand(1, 20));
        $hasil = [$tanggal->copy()];

        for ($bulan = 2; $bulan <= 3; $bulan++) {
            if ($pola >= 9 && $bulan === 3) {
                break; // tidak datang di bulan Mei → muncul di Early Warning
            }
            $geser = match (true) {
                $pola <= 6 => mt_rand(-3, 3),
                $pola <= 8 => mt_rand(0, 1) ? mt_rand(5, 14) : mt_rand(-2, 2),
                default    => mt_rand(-2, 6),
            };
            $tanggal = $tanggal->copy()->addDays(30 + $geser);
            if ($tanggal->greaterThan(Carbon::create(2026, 5, 31))) {
                break;
            }
            $hasil[] = $tanggal->copy();
        }

        return $hasil;
    }

    /**
     * Beberapa pasien Pasif / Meninggal dan contoh tindak lanjut agar semua
     * filter dan menu bisa dicoba.
     */
    private function statusDanTindakLanjut(array $semuaPasien, string $petugas): void
    {
        $cari = fn (int $n) => PasienPrb::cariPerDiagnosis(...$semuaPasien[$n % count($semuaPasien)]);

        foreach ([5, 23, 41] as $n) {
            $cari($n)?->update([
                'status'       => 'Pasif',
                'alasan_pasif' => PasienPrb::ALASAN_PASIF_OPTIONS[$n % count(PasienPrb::ALASAN_PASIF_OPTIONS)],
            ]);
        }

        // Pasien yang tidak datang bulan Mei → dihubungi petugas
        $kategori = ['Kontak langsung', 'Koordinasi FKTP', 'Kembali ke FKRTL', 'Lain-lain', 'Kontak langsung'];
        $tidakDatang = PasienPrb::where('status', 'Aktif')
            ->where('tanggal_pengambilan_terakhir', '<', '2026-05-01')
            ->orderBy('kode_pasien')
            ->take(count($kategori) + 1)
            ->get();

        foreach ($tidakDatang as $idx => $pasien) {
            $kat = $kategori[$idx] ?? 'Meninggal';
            RiwayatTindakLanjut::create([
                'pasien_prb_id'             => $pasien->id,
                'tanggal_jam'               => Carbon::create(2026, 5, 20 + $idx, 9, 30),
                'petugas'                   => $petugas,
                'tindakan'                  => 'Belum mengambil obat',
                'kategori'                  => $kat,
                'catatan'                   => $kat === 'Meninggal'
                    ? 'Informasi dari keluarga pasien (data demo).'
                    : 'Contoh catatan tindak lanjut (data demo).',
                'update_realisasi_otomatis' => false,
            ]);
            if ($kat === 'Meninggal') {
                $pasien->update(['status' => 'Meninggal']);
            }
        }
    }
}
