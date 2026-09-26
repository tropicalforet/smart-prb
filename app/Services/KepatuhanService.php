<?php

namespace App\Services;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;

class KepatuhanService
{
    /**
     * Toleransi pengambilan obat:
     * H-3 sampai H+3 dari tanggal jadwal.
     */
    private const TOLERANSI_SEBELUM = 3;
    private const TOLERANSI_SESUDAH = 3;

    /**
     * Hitung tanggal jadwal pengambilan berikutnya.
     */
    public function hitungJadwalBerikutnya(
        string|Carbon $tanggalPengambilan,
        int $jatahObat
    ): Carbon {
        $tanggal = $tanggalPengambilan instanceof Carbon
            ? $tanggalPengambilan
            : Carbon::parse($tanggalPengambilan);

        return $tanggal->copy()->startOfDay()->addDays($jatahObat);
    }

    /**
     * Tentukan status kepatuhan dan early warning pasien.
     *
     * Aturan kepatuhan:
     *
     * - sebelum H-3  : Belum Jatuh Tempo
     * - H-3 s.d. H+3 : Dalam Toleransi
     * - lewat H+3    : Tidak Patuh / Overdue
     *
     * Early Warning:
     * - sebelum H-3  : Belum Jatuh Tempo
     * - H-3 s.d. H-1 : Jadwal Mendekat
     * - H            : Hari Pengambilan
     * - H+1 s.d. H+3 : Masih Toleransi
     * - lewat H+3    : Overdue
     *
     * Jika sudah ada realisasi:
     * - H-3 s.d. H+3 : Patuh
     * - sebelum H-3  : Tidak Patuh (Pengambilan terlalu awal)
     * - setelah H+3  : Tidak Patuh (Terlambat X hari, melewati batas toleransi H+3)
     *
     * Pasien pasif tidak masuk pemantauan aktif.
     */
    public function tentukanStatusKepatuhan(
        PasienPrb $pasien,
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi
            ? Carbon::parse($tanggalEvaluasi)->startOfDay()
            : Carbon::today()->startOfDay();

        // ---------------------------------------------------------
        // 1. PASIEN PASIF
        // ---------------------------------------------------------
        if ($pasien->status === 'Pasif') {
            return [
                'status' => 'Pasif',
                'early_warning' => 'Abu-abu',
                'selisih_hari' => 0,
                'keterangan' => $pasien->alasan_pasif ?? 'Dikeluarkan dari pemantauan aktif',
                'tindakan' => '-',
                'jadwal_target_aktif' => null,
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // ---------------------------------------------------------
        // 2. BELUM ADA JADWAL
        // ---------------------------------------------------------
        $jadwalAktif = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->startOfDay()
            : null;

        if (!$jadwalAktif) {
            return [
                'status' => 'Belum Ada Jadwal',
                'early_warning' => 'Abu-abu',
                'selisih_hari' => 0,
                'keterangan' => 'Belum ada jadwal pengambilan obat yang dapat dinilai',
                'tindakan' => '-',
                'jadwal_target_aktif' => null,
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // ---------------------------------------------------------
        // 3. EVALUASI SIKLUS AKTIF (H-3 s.d H+3)
        // ---------------------------------------------------------
        $batasAwalToleransi = $jadwalAktif->copy()->subDays(self::TOLERANSI_SEBELUM);
        $batasAkhirToleransi = $jadwalAktif->copy()->addDays(self::TOLERANSI_SESUDAH);
        $selisihEvaluasi = (int) $jadwalAktif->diffInDays($evaluasi, false);

        // Cek realisasi pada siklus aktif
        $realisasiSiklusAktif = null;
        if ($pasien->tanggal_realisasi) {
            $tglRealisasi = Carbon::parse($pasien->tanggal_realisasi)->startOfDay();
            if ($tglRealisasi->between($batasAwalToleransi, $batasAkhirToleransi, true)) {
                $realisasiSiklusAktif = $tglRealisasi;
            }
        }

        // Jika sudah mengambil pada H-3 s.d H+3
        if ($realisasiSiklusAktif) {
            $selisihRealisasi = (int) $jadwalAktif->diffInDays($realisasiSiklusAktif, false);
            return [
                'status' => 'Patuh',
                'early_warning' => 'Hijau - Patuh',
                'selisih_hari' => $selisihRealisasi,
                'keterangan' => 'Pengambilan obat dilakukan tepat dalam masa toleransi',
                'tindakan' => 'Tidak perlu tindak lanjut',
                'jadwal_target_aktif' => $jadwalAktif->toDateString(),
                'tanggal_realisasi_aktif' => $realisasiSiklusAktif->toDateString(),
            ];
        }

        // BELUM MENGAMBIL: KONDISI 1: Hari ini < (jadwal - 3 hari)
        if ($evaluasi->lt($batasAwalToleransi)) {
            return [
                'status' => 'Belum Jatuh Tempo',
                'early_warning' => 'Biru - Belum memasuki jadwal',
                'selisih_hari' => $selisihEvaluasi,
                'keterangan' => 'Belum memasuki periode pengambilan',
                'tindakan' => 'Belum perlu tindakan, bisa dilayani',
                'jadwal_target_aktif' => $jadwalAktif->toDateString(),
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // BELUM MENGAMBIL: KONDISI 2: Hari ini berada pada H-3 s.d H+3
        if ($evaluasi->lte($batasAkhirToleransi)) {
            if ($evaluasi->lt($jadwalAktif)) {
                $earlyWarning = 'Kuning - Jadwal mendekat';
                $tindakan = 'Reminder kepada pasien';
                $keterangan = 'Jadwal pengambilan mendekat';
            } elseif ($evaluasi->equalTo($jadwalAktif)) {
                $earlyWarning = 'Oranye - Jatuh tempo';
                $tindakan = 'Reminder lebih aktif';
                $keterangan = 'Hari ini merupakan tanggal pengambilan obat';
            } else {
                $earlyWarning = 'Oranye - Belum mengambil, masih toleransi';
                $tindakan = 'Hubungi pasien dan informasikan FKTP';
                $keterangan = 'Belum mengambil obat, masih dalam batas toleransi sampai H+3';
            }

            return [
                'status' => 'Dalam Toleransi',
                'early_warning' => $earlyWarning,
                'selisih_hari' => $selisihEvaluasi,
                'keterangan' => $keterangan,
                'tindakan' => $tindakan,
                'jadwal_target_aktif' => $jadwalAktif->toDateString(),
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // BELUM MENGAMBIL / LEWAT BATAS: KONDISI 3: Hari ini > (jadwal + 3 hari)
        return [
            'status' => 'Tidak Patuh',
            'early_warning' => 'Merah - Tidak patuh/Overdue',
            'selisih_hari' => $selisihEvaluasi,
            'keterangan' => 'Terlambat ' . $selisihEvaluasi . ' hari, melewati batas toleransi H+3',
            'tindakan' => 'Segera dilaporkan kepada FKTP untuk ditindaklanjuti',
            'jadwal_target_aktif' => $jadwalAktif->toDateString(),
            'tanggal_realisasi_aktif' => null,
        ];
    }

    /**
     * Tentukan status kepatuhan dan early warning berdasarkan periode tertentu.
     * Mengutamakan data transaksi riwayat (jika ada).
     */
    public function tentukanStatusKepatuhanPeriode(
        PasienPrb $pasien,
        string $periode // format YYYY-MM
    ): array {
        // 1. Cari riwayat di bulan tersebut
        $riwayat = $pasien->riwayatPengambilanObat
            ->filter(function ($r) use ($periode) {
                if ($r->tanggal_pengambilan && str_starts_with($r->tanggal_pengambilan->toDateString(), $periode)) {
                    return true;
                }
                if ($r->jadwal_target && str_starts_with($r->jadwal_target->toDateString(), $periode)) {
                    return true;
                }
                return false;
            })
            ->first();

        if ($riwayat) {
            return [
                'status' => $riwayat->status_kepatuhan,
                'early_warning' => '-', // Sesuai aturan: Jangan mencampurkan EW dengan status historical
                'selisih_hari' => $riwayat->selisih_hari,
                'keterangan' => 'Berdasarkan riwayat transaksi bulan ini',
                'jadwal_target_aktif' => $riwayat->jadwal_target ? $riwayat->jadwal_target->toDateString() : null,
                'tanggal_realisasi_aktif' => $riwayat->tanggal_pengambilan ? $riwayat->tanggal_pengambilan->toDateString() : null,
            ];
        }

        // 2. Jika tidak ada riwayat, cek apakah jadwal master pasien jatuh pada periode ini
        $jadwalAktif = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->startOfDay()
            : null;

        if ($jadwalAktif && str_starts_with($jadwalAktif->toDateString(), $periode)) {
            // Pasien ADA DI SIKLUS INI tetapi BELUM TRANSAKSI.
            // Hitung status MURNI berdasarkan jadwal ini (tanpa mewariskan transaksi sebelumnya).
            $batasAwal = $jadwalAktif->copy()->subDays(self::TOLERANSI_SEBELUM);
            $batasAkhir = $jadwalAktif->copy()->addDays(self::TOLERANSI_SESUDAH);
            $hariIni = Carbon::today()->startOfDay();

            $status = 'Dalam Toleransi';
            $selisih = (int) $jadwalAktif->diffInDays($hariIni, false);

            if ($hariIni->lt($batasAwal)) {
                $status = 'Belum Jatuh Tempo';
            } elseif ($hariIni->gt($batasAkhir)) {
                $status = 'Tidak Patuh';
            }

            return [
                'status' => $status,
                'early_warning' => '-', 
                'selisih_hari' => $selisih,
                'keterangan' => 'Belum ada realisasi untuk siklus bulan ini',
                'jadwal_target_aktif' => $jadwalAktif->toDateString(),
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // 3. Jika jadwal master BUKAN di periode ini dan tidak ada riwayat di periode ini
        return [
            'status' => 'Tidak Ada Transaksi',
            'early_warning' => '-',
            'selisih_hari' => 0,
            'keterangan' => 'Tidak terdapat riwayat transaksi pada periode ini',
            'jadwal_target_aktif' => null, // Harus null agar tidak muncul jadwal bulan lain
            'tanggal_realisasi_aktif' => null,
        ];
    }

    /**
     * Proses realisasi pengambilan obat.
     *
     * Alur:
     * realisasi
     * -> simpan riwayat
     * -> update tanggal pengambilan terakhir
     * -> hitung jadwal berikutnya
     * -> reset tanggal realisasi.
     */
    public function prosesRealisasi(
        PasienPrb $pasien,
        string $tanggalRealisasi
    ): PasienPrb {
        $realisasi = Carbon::parse($tanggalRealisasi)->startOfDay();

        $jadwalTarget = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse(
                $pasien->tanggal_jadwal_berikutnya
            )->startOfDay()
            : null;

        // Jika master sudah maju ke siklus berikutnya (Belum Jatuh Tempo),
        // berarti realisasi ini sebenarnya untuk siklus sebelumnya.
        // Kita gunakan jadwal_target dari riwayat terakhir.
        if ($jadwalTarget) {
            $batasAwalToleransi = $jadwalTarget->copy()->subDays(self::TOLERANSI_SEBELUM);
            if ($realisasi->lt($batasAwalToleransi)) {
                $riwayatTerakhir = $pasien->riwayatPengambilanObat()->latest('tanggal_pengambilan')->first();
                if ($riwayatTerakhir && $riwayatTerakhir->jadwal_target) {
                    $jadwalTarget = Carbon::parse($riwayatTerakhir->jadwal_target)->startOfDay();
                }
            }
        }

        $selisihHari = 0;
        $statusKepatuhan = 'Patuh';

        if ($jadwalTarget) {
            $batasAwalToleransi = $jadwalTarget
                ->copy()
                ->subDays(self::TOLERANSI_SEBELUM);

            $batasAkhirToleransi = $jadwalTarget
                ->copy()
                ->addDays(self::TOLERANSI_SESUDAH);

            $selisihHari = (int) $jadwalTarget->diffInDays(
                $realisasi,
                false
            );

            $statusKepatuhan = $realisasi->between(
                $batasAwalToleransi,
                $batasAkhirToleransi,
                true
            )
                ? 'Patuh'
                : 'Tidak Patuh';
        }

        // ---------------------------------------------------------
        // SIMPAN RIWAYAT
        // ---------------------------------------------------------
        RiwayatPengambilanObat::create([
            'pasien_prb_id' => $pasien->id,
            'tanggal_pengambilan' => $realisasi->toDateString(),
            'jadwal_target' => $jadwalTarget?->toDateString()
                ?? $realisasi->toDateString(),
            'selisih_hari' => $selisihHari,
            'status_kepatuhan' => $statusKepatuhan,
        ]);

        // ---------------------------------------------------------
        // TENTUKAN SIKLUS BERIKUTNYA
        // ---------------------------------------------------------
        $jatahObat = $pasien->jatah_obat;

        // PRB Baru: setelah pengambilan pertama,
        // masuk ke siklus PRB Lama 30 hari.
        if ($pasien->jenis_prb === 'Baru') {
            $pasien->jenis_prb = 'Lama';
            $pasien->jatah_obat = 30;
            $jatahObat = 30;
        }

        // Jika jatah obat belum tersedia, gunakan 30 hari.
        if (!$jatahObat || $jatahObat < 1) {
            $jatahObat = 30;
            $pasien->jatah_obat = 30;
        }

        $jadwalBaru = $this->hitungJadwalBerikutnya(
            $realisasi,
            $jatahObat
        );

        // ---------------------------------------------------------
        // UPDATE PASIEN
        // ---------------------------------------------------------
        $pasien->update([
            'tanggal_pengambilan_terakhir' =>
                $realisasi->toDateString(),

            'tanggal_jadwal_berikutnya' =>
                $jadwalBaru->toDateString(),

            'tanggal_realisasi' =>
                $realisasi->toDateString(),

            'jenis_prb' => $pasien->jenis_prb,

            'jatah_obat' => $pasien->jatah_obat,
        ]);

        return $pasien->fresh();
    }

    /**
     * Hitung statistik dashboard.
     *
     * Catatan:
     * Statistik ini merupakan statistik berdasarkan kondisi
     * pasien pada tanggal evaluasi.
     *
     * Pasien yang masih Belum Jatuh Tempo atau Dalam Toleransi
     * tanpa realisasi belum dihitung sebagai Patuh.
     *
     * KPI evaluasi bulanan perlu menggunakan denominator
     * pasien yang periode H-3 s.d. H+3-nya sudah selesai.
     */
    public function hitungStatistikDashboard(
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi
            ?: Carbon::today()->toDateString();

        $semuaPasien = PasienPrb::all();

        $totalPasien = $semuaPasien->count();

        $pasienAktif = $semuaPasien->where(
            'status',
            'Aktif'
        );

        $pasienPasif = $semuaPasien->where(
            'status',
            'Pasif'
        );

        $patuh = 0;
        $tidakPatuh = 0;
        $dalamToleransi = 0;
        $belumJatuhTempo = 0;

        foreach ($pasienAktif as $pasien) {
            $kepatuhan = $this->tentukanStatusKepatuhan(
                $pasien,
                $evaluasi
            );

            switch ($kepatuhan['status']) {
                case 'Patuh':
                    $patuh++;
                    break;

                case 'Tidak Patuh':
                    $tidakPatuh++;
                    break;

                case 'Dalam Toleransi':
                    $dalamToleransi++;
                    break;

                case 'Belum Jatuh Tempo':
                    $belumJatuhTempo++;
                    break;
            }
        }

        $totalAktif = $pasienAktif->count();

        // Hanya pasien dengan hasil Patuh/Tidak Patuh
        // yang sudah dapat dinilai.
        $pasienDapatDinilai = $patuh + $tidakPatuh;

        $persentasePatuh = $pasienDapatDinilai > 0
            ? round(
                ($patuh / $pasienDapatDinilai) * 100,
                1
            )
            : 0;

        // ---------------------------------------------------------
        // STATISTIK PER DIAGNOSIS
        // ---------------------------------------------------------
        $perDiagnosis = [];

        foreach (PasienPrb::DIAGNOSIS_OPTIONS as $diagnosis) {
            $pasienDiag = $pasienAktif->where(
                'diagnosis_prb',
                $diagnosis
            );

            $diagPatuh = 0;
            $diagTidakPatuh = 0;

            foreach ($pasienDiag as $p) {
                $k = $this->tentukanStatusKepatuhan(
                    $p,
                    $evaluasi
                );

                if ($k['status'] === 'Patuh') {
                    $diagPatuh++;
                }

                if ($k['status'] === 'Tidak Patuh') {
                    $diagTidakPatuh++;
                }
            }

            $diagTotalDinilai =
                $diagPatuh + $diagTidakPatuh;

            if ($diagTotalDinilai > 0) {
                $perDiagnosis[] = [
                    'diagnosis' => $diagnosis,
                    'total' => $diagTotalDinilai,
                    'patuh' => $diagPatuh,
                    'tidak_patuh' => $diagTidakPatuh,
                    'persentase' => round(
                        ($diagPatuh / $diagTotalDinilai) * 100,
                        1
                    ),
                ];
            }
        }

        // ---------------------------------------------------------
        // STATISTIK PER FKTP
        // ---------------------------------------------------------
        $fktpList = $pasienAktif
            ->pluck('fktp_asal')
            ->filter()
            ->unique();

        $perFktp = [];

        foreach ($fktpList as $fktp) {
            $pasienFktp = $pasienAktif->where(
                'fktp_asal',
                $fktp
            );

            $fktpPatuh = 0;
            $fktpTidakPatuh = 0;

            foreach ($pasienFktp as $p) {
                $k = $this->tentukanStatusKepatuhan(
                    $p,
                    $evaluasi
                );

                if ($k['status'] === 'Patuh') {
                    $fktpPatuh++;
                }

                if ($k['status'] === 'Tidak Patuh') {
                    $fktpTidakPatuh++;
                }
            }

            $fktpTotalDinilai =
                $fktpPatuh + $fktpTidakPatuh;

            $perFktp[] = [
                'fktp' => $fktp,
                'total' => $fktpTotalDinilai,
                'patuh' => $fktpPatuh,
                'tidak_patuh' => $fktpTidakPatuh,
                'persentase' => $fktpTotalDinilai > 0
                    ? round(
                        ($fktpPatuh / $fktpTotalDinilai) * 100,
                        1
                    )
                    : 0,
            ];
        }

        return [
            'total_pasien' => $totalPasien,
            'total_aktif' => $totalAktif,
            'total_pasif' => $pasienPasif->count(),

            'patuh' => $patuh,
            'tidak_patuh' => $tidakPatuh,
            'dalam_toleransi' => $dalamToleransi,
            'belum_jatuh_tempo' => $belumJatuhTempo,

            'persentase_patuh' => $persentasePatuh,

            'per_diagnosis' => $perDiagnosis,
            'per_fktp' => $perFktp,

            'tanggal_evaluasi' => $evaluasi,
        ];
    }
}