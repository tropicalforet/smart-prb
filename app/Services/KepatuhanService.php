<?php

namespace App\Services;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class KepatuhanService
{
    /**
     * Hitung tanggal jadwal pengambilan berikutnya.
     */
    public function hitungJadwalBerikutnya(string|Carbon $tanggalPengambilan, int $jatahObat): Carbon
    {
        $tanggal = $tanggalPengambilan instanceof Carbon
            ? $tanggalPengambilan
            : Carbon::parse($tanggalPengambilan);

        return $tanggal->copy()->addDays($jatahObat);
    }

    /**
     * Tentukan status kepatuhan berdasarkan aturan bisnis.
     *
     * Rules:
     * - Patuh: Realisasi ada dan dalam rentang H-3 s.d. H+7
     * - Dalam Toleransi: Belum ada realisasi, tanggal evaluasi <= H+7
     * - Tidak Patuh: Realisasi > H+7 atau belum realisasi & evaluasi > H+7
     * - Pasif: Status pasien non-aktif
     */
    public function tentukanStatusKepatuhan(
        PasienPrb $pasien,
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi ? Carbon::parse($tanggalEvaluasi) : Carbon::today();

        // Pasien pasif tidak dikalkulasi
        if ($pasien->status === 'Pasif') {
            return [
                'status' => 'Pasif',
                'selisih_hari' => 0,
                'keterangan' => $pasien->alasan_pasif ?? 'Pasien tidak aktif',
            ];
        }

        $jadwal = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)
            : null;

        // Jika belum ada jadwal (pasien baru belum pernah ambil obat)
        if (!$jadwal) {
            return [
                'status' => 'Belum Ada Jadwal',
                'selisih_hari' => 0,
                'keterangan' => 'Belum ada data pengambilan obat',
            ];
        }

        $batasAwalToleransi = $jadwal->copy()->subDays(3); // H-3
        $batasAkhirToleransi = $jadwal->copy()->addDays(7); // H+7

        $realisasi = $pasien->tanggal_realisasi
            ? Carbon::parse($pasien->tanggal_realisasi)
            : null;

        // Jika ada realisasi pengambilan
        if ($realisasi) {
            $selisihHari = $jadwal->diffInDays($realisasi, false);

            if ($realisasi->between($batasAwalToleransi, $batasAkhirToleransi)) {
                return [
                    'status' => 'Patuh',
                    'selisih_hari' => (int) $selisihHari,
                    'keterangan' => $selisihHari <= 0
                        ? 'Mengambil tepat waktu'
                        : "Mengambil {$selisihHari} hari setelah jadwal (dalam toleransi)",
                ];
            }

            // Realisasi setelah H+7
            return [
                'status' => 'Tidak Patuh',
                'selisih_hari' => (int) $selisihHari,
                'keterangan' => "Terlambat {$selisihHari} hari dari jadwal",
            ];
        }

        // Belum ada realisasi — cek apakah masih dalam toleransi
        if ($evaluasi->lte($batasAkhirToleransi)) {
            $selisihHari = $jadwal->diffInDays($evaluasi, false);
            if ($evaluasi->lt($batasAwalToleransi)) {
                return [
                    'status' => 'Belum Jatuh Tempo',
                    'selisih_hari' => (int) $selisihHari,
                    'keterangan' => 'Jadwal pengambilan belum tiba',
                ];
            }
            return [
                'status' => 'Dalam Toleransi',
                'selisih_hari' => (int) max(0, $selisihHari),
                'keterangan' => $selisihHari <= 0
                    ? 'Mendekati jadwal pengambilan'
                    : "Sudah {$selisihHari} hari dari jadwal, masih dalam toleransi",
            ];
        }

        // Sudah lewat H+7 dan belum ada realisasi
        $selisihHari = $jadwal->diffInDays($evaluasi, false);
        return [
            'status' => 'Tidak Patuh',
            'selisih_hari' => (int) $selisihHari,
            'keterangan' => "Terlambat {$selisihHari} hari, belum mengambil obat",
        ];
    }

    /**
     * Proses realisasi pengambilan obat baru.
     * - Buat riwayat pengambilan
     * - Update tanggal pengambilan terakhir
     * - Hitung jadwal berikutnya
     * - Reset realisasi untuk siklus baru
     */
    public function prosesRealisasi(
        PasienPrb $pasien,
        string $tanggalRealisasi
    ): PasienPrb {
        $realisasi = Carbon::parse($tanggalRealisasi);
        $jadwalTarget = $pasien->tanggal_jadwal_berikutnya;

        // Hitung selisih hari dari jadwal
        $selisihHari = 0;
        $statusKepatuhan = 'Patuh';

        if ($jadwalTarget) {
            $jadwal = Carbon::parse($jadwalTarget);
            $batasAwalToleransi = $jadwal->copy()->subDays(3);
            $batasAkhirToleransi = $jadwal->copy()->addDays(7);
            $selisihHari = (int) $jadwal->diffInDays($realisasi, false);

            $statusKepatuhan = $realisasi->between($batasAwalToleransi, $batasAkhirToleransi)
                ? 'Patuh'
                : 'Terlambat';
        }

        // Buat riwayat pengambilan baru
        RiwayatPengambilanObat::create([
            'pasien_prb_id' => $pasien->id,
            'tanggal_pengambilan' => $realisasi->toDateString(),
            'jadwal_target' => $jadwalTarget?->toDateString() ?? $realisasi->toDateString(),
            'selisih_hari' => $selisihHari,
            'status_kepatuhan' => $statusKepatuhan,
        ]);

        // Jika sebelumnya Pasien Baru, maka sekarang otomatis jadi Lama untuk jadwal berikutnya
        $jatahObat = $pasien->jatah_obat;
        if ($pasien->jenis_prb === 'Baru') {
            $pasien->jenis_prb = 'Lama';
            $pasien->jatah_obat = 30; // Siklus berikutnya 30 hari
            $jatahObat = 30;
        }

        // Update pasien: geser siklus
        $jadwalBaru = $this->hitungJadwalBerikutnya($realisasi, $jatahObat);
        $pasien->update([
            'tanggal_pengambilan_terakhir' => $realisasi->toDateString(),
            'tanggal_jadwal_berikutnya' => $jadwalBaru->toDateString(),
            'tanggal_realisasi' => null, // Reset untuk siklus baru
            'jenis_prb' => $pasien->jenis_prb,
            'jatah_obat' => $pasien->jatah_obat,
        ]);

        return $pasien->fresh();
    }

    /**
     * Hitung statistik dashboard KPI.
     */
    public function hitungStatistikDashboard(?string $tanggalEvaluasi = null): array
    {
        $evaluasi = $tanggalEvaluasi ?: Carbon::today()->toDateString();

        $semuaPasien = PasienPrb::all();
        $totalPasien = $semuaPasien->count();
        $pasienAktif = $semuaPasien->where('status', 'Aktif');
        $pasienPasif = $semuaPasien->where('status', 'Pasif');

        $patuh = 0;
        $tidakPatuh = 0;
        $dalamToleransi = 0;
        $belumJatuhTempo = 0;
        $pasienPatuh = [];
        $pasienTidakPatuh = [];
        $pasienToleransi = [];

        foreach ($pasienAktif as $pasien) {
            $kepatuhan = $this->tentukanStatusKepatuhan($pasien, $evaluasi);

            switch ($kepatuhan['status']) {
                case 'Patuh':
                    $patuh++;
                    $pasienPatuh[] = $pasien->id;
                    break;
                case 'Tidak Patuh':
                    $tidakPatuh++;
                    $pasienTidakPatuh[] = $pasien->id;
                    break;
                case 'Dalam Toleransi':
                    $dalamToleransi++;
                    $pasienToleransi[] = $pasien->id;
                    break;
                case 'Belum Jatuh Tempo':
                    $belumJatuhTempo++;
                    $pasienPatuh[] = $pasien->id; // Count as compliant for KPI
                    break;
            }
        }

        $totalAktif = $pasienAktif->count();
        $persentasePatuh = $totalAktif > 0
            ? round((($patuh + $belumJatuhTempo) / $totalAktif) * 100, 1)
            : 0;

        // Statistik per diagnosis
        $perDiagnosis = [];
        foreach (PasienPrb::DIAGNOSIS_OPTIONS as $diagnosis) {
            $pasienDiag = $pasienAktif->where('diagnosis_prb', $diagnosis);
            $diagPatuh = 0;
            $diagTotal = $pasienDiag->count();

            foreach ($pasienDiag as $p) {
                $k = $this->tentukanStatusKepatuhan($p, $evaluasi);
                if (in_array($k['status'], ['Patuh', 'Belum Jatuh Tempo'])) {
                    $diagPatuh++;
                }
            }

            if ($diagTotal > 0) {
                $perDiagnosis[] = [
                    'diagnosis' => $diagnosis,
                    'total' => $diagTotal,
                    'patuh' => $diagPatuh,
                    'tidak_patuh' => $diagTotal - $diagPatuh,
                    'persentase' => round(($diagPatuh / $diagTotal) * 100, 1),
                ];
            }
        }

        // Statistik per FKTP
        $fktpList = $pasienAktif->pluck('fktp_asal')->unique();
        $perFktp = [];
        foreach ($fktpList as $fktp) {
            $pasienFktp = $pasienAktif->where('fktp_asal', $fktp);
            $fktpPatuh = 0;
            $fktpTotal = $pasienFktp->count();

            foreach ($pasienFktp as $p) {
                $k = $this->tentukanStatusKepatuhan($p, $evaluasi);
                if (in_array($k['status'], ['Patuh', 'Belum Jatuh Tempo'])) {
                    $fktpPatuh++;
                }
            }

            $perFktp[] = [
                'fktp' => $fktp,
                'total' => $fktpTotal,
                'patuh' => $fktpPatuh,
                'tidak_patuh' => $fktpTotal - $fktpPatuh,
                'persentase' => round(($fktpPatuh / $fktpTotal) * 100, 1),
            ];
        }

        return [
            'total_pasien' => $totalPasien,
            'total_aktif' => $totalAktif,
            'total_pasif' => $pasienPasif->count(),
            'patuh' => $patuh + $belumJatuhTempo,
            'tidak_patuh' => $tidakPatuh,
            'dalam_toleransi' => $dalamToleransi,
            'persentase_patuh' => $persentasePatuh,
            'per_diagnosis' => $perDiagnosis,
            'per_fktp' => $perFktp,
            'tanggal_evaluasi' => $evaluasi,
        ];
    }
}
