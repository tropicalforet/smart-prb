<?php

namespace App\Services;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     * =========================================================
     * TENTUKAN STATUS KEPATUHAN — MODE SEMUA PERIODE
     * =========================================================
     *
     * PRINSIP SRS SMART PRB:
     *   EWS dan Status Kepatuhan adalah DUA hal TERPISAH.
     *
     *   EWS    → jadwal AKTIF (berikutnya) vs tanggal evaluasi
     *   Status → siklus TERAKHIR yang sudah selesai (riwayat terbaru)
     *
     * ── A. EARLY WARNING dari jadwal aktif ──
     *   selisih = evaluasi − jadwal_aktif
     *   < −3  → Belum memasuki jadwal
     *   −3..−1 → Jadwal mendekat
     *   0      → Jatuh tempo
     *   1..3   → Belum mengambil, masih toleransi
     *   ≥ 4    → Tidak patuh/Overdue
     *
     * ── B. STATUS dari riwayat terbaru ──
     *   Ada riwayat → status = status_kepatuhan riwayat terbaru
     *   Tidak ada riwayat → status diturunkan dari EWS
     *
     * Kombinasi VALID:
     *   EWS=Belum memasuki jadwal + Status=Patuh     → riwayat Patuh + jadwal belum tiba
     *   EWS=Belum memasuki jadwal + Status=Tidak Patuh → riwayat Tidak Patuh + jadwal belum tiba
     *   EWS=Jatuh tempo           + Status=Patuh     → riwayat Patuh + hari ini = jadwal aktif
     */
    public function tentukanStatusKepatuhan(
        PasienPrb $pasien,
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi
            ? Carbon::parse($tanggalEvaluasi)->startOfDay()
            : Carbon::today()->startOfDay();

        // ── Pasif ─────────────────────────────────────────────────────────
        if ($pasien->status === 'Pasif') {
            return [
                'status'                  => 'Pasif',
                'early_warning'           => 'Pasif',
                'selisih_hari'            => 0,
                'keterangan'              => $pasien->alasan_pasif ?? 'Dikeluarkan dari pemantauan aktif',
                'tindakan'                => '-',
                'jadwal_target_aktif'     => null,
                'tanggal_realisasi_aktif' => null,
                'riwayat_id_aktif'        => null,
            ];
        }

        // ── Jadwal aktif (sumber EWS) ─────────────────────────────────────
        $jadwalAktif = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->startOfDay()
            : null;

        // ── Riwayat terbaru valid (sumber Status & Kedatangan) ────────────
        // Ambil riwayat dengan tanggal_pengambilan terbesar, |selisih| ≤ 90.
        // Ini adalah siklus terakhir yang sudah selesai — tidak peduli apakah
        // jadwal_target-nya sama/berbeda dengan jadwal aktif.
        $riwayatTerbaru = $pasien->riwayatPengambilanObat
            ->filter(fn($r) => !empty($r->tanggal_pengambilan)
                && !empty($r->jadwal_target)
                && abs($r->selisih_hari ?? 0) <= 90)
            ->sortByDesc('tanggal_pengambilan')
            ->first();

        // ── Hitung EWS dari jadwal aktif ──────────────────────────────────
        $earlyWarning  = '-';
        $keteranganEws = '';
        $selisihEws    = 0;

        if ($jadwalAktif) {
            // selisih positif = evaluasi setelah jadwal (terlambat)
            // selisih negatif = evaluasi sebelum jadwal (belum waktunya)
            $selisihEws = (int) $jadwalAktif->diffInDays($evaluasi, false);

            if ($selisihEws < -(self::TOLERANSI_SEBELUM)) {
                // sebelum H-3
                $earlyWarning  = 'Belum memasuki jadwal';
                $keteranganEws = 'Belum memasuki periode pengambilan';
            } elseif ($selisihEws < 0) {
                // H-3 s/d H-1
                $earlyWarning  = 'Jadwal mendekat';
                $keteranganEws = 'Jadwal pengambilan mendekat';
            } elseif ($selisihEws === 0) {
                // tepat H
                $earlyWarning  = 'Jatuh tempo';
                $keteranganEws = 'Hari ini merupakan tanggal pengambilan obat';
            } elseif ($selisihEws <= self::TOLERANSI_SESUDAH) {
                // H+1 s/d H+3
                $earlyWarning  = 'Belum mengambil, masih toleransi';
                $keteranganEws = 'Belum mengambil obat, masih dalam batas toleransi sampai H+3';
            } else {
                // H+4 ke atas
                $earlyWarning  = 'Tidak patuh/Overdue';
                $keteranganEws = 'Terlambat ' . $selisihEws . ' hari, melewati batas toleransi H+3';
            }
        }

        // ── Ada riwayat: Status & Kedatangan dari siklus terakhir selesai ─
        if ($riwayatTerbaru) {
            $tglRealisasi    = Carbon::parse($riwayatTerbaru->tanggal_pengambilan)->startOfDay();
            $selisihHistoris = $riwayatTerbaru->selisih_hari
                ?? (int) Carbon::parse($riwayatTerbaru->jadwal_target)
                               ->startOfDay()
                               ->diffInDays($tglRealisasi, false);
            $statusHistoris  = $riwayatTerbaru->status_kepatuhan ?? 'Tidak Patuh';

            $keteranganHistoris = match(true) {
                $statusHistoris === 'Patuh'
                    => 'Pengambilan obat siklus terakhir telah direalisasikan.',
                $selisihHistoris < 0
                    => abs($selisihHistoris) . ' hari lebih awal, di luar batas toleransi H-3',
                default
                    => 'Terlambat ' . $selisihHistoris . ' hari, melewati batas toleransi H+3',
            };

            return [
                'status'                  => $statusHistoris,
                'early_warning'           => $earlyWarning,
                'selisih_hari'            => $selisihHistoris,
                'keterangan'              => $keteranganHistoris,
                'tindakan'                => $statusHistoris === 'Patuh'
                                                ? 'Pantau jadwal selanjutnya'
                                                : 'Tinjau pola kepatuhan pasien',
                'jadwal_target_aktif'     => $jadwalAktif?->toDateString(),
                'tanggal_realisasi_aktif' => $tglRealisasi->toDateString(),
                'riwayat_id_aktif'        => $riwayatTerbaru->id,
            ];
        }

        // ── Tidak ada riwayat: Status diturunkan dari EWS ─────────────────
        if (!$jadwalAktif) {
            return [
                'status'                  => 'Belum Ada Jadwal',
                'early_warning'           => '-',
                'selisih_hari'            => 0,
                'keterangan'              => 'Belum ada jadwal pengambilan obat',
                'tindakan'                => '-',
                'jadwal_target_aktif'     => null,
                'tanggal_realisasi_aktif' => null,
                'riwayat_id_aktif'        => null,
            ];
        }

        $statusTanpaRiwayat = match(true) {
            $earlyWarning === 'Belum memasuki jadwal'
            || $earlyWarning === 'Jadwal mendekat'
                => 'Belum Jatuh Tempo',
            $earlyWarning === 'Jatuh tempo'
            || $earlyWarning === 'Belum mengambil, masih toleransi'
                => 'Belum Diambil',
            default   // Tidak patuh/Overdue
                => 'Tidak Patuh',
        };

        $tindakanTanpaRiwayat = match($earlyWarning) {
            'Tidak patuh/Overdue'                  => 'Segera laporkan ke FKTP',
            'Jatuh tempo',
            'Belum mengambil, masih toleransi'     => 'Hubungi pasien segera',
            'Jadwal mendekat'                       => 'Kirim reminder ke pasien',
            default                                 => 'Pantau jadwal',
        };

        return [
            'status'                  => $statusTanpaRiwayat,
            'early_warning'           => $earlyWarning,
            'selisih_hari'            => $selisihEws,
            'keterangan'              => $keteranganEws,
            'tindakan'                => $tindakanTanpaRiwayat,
            'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
            'tanggal_realisasi_aktif' => null,
            'riwayat_id_aktif'        => null,
        ];
    }

    /**
     * =========================================================
     * TENTUKAN STATUS KEPATUHAN — MODE PERIODE TERTENTU
     * Digunakan saat user memilih periode spesifik (format "YYYY-MM").
     * Evaluasi HANYA terhadap siklus yang jadwal_target-nya jatuh
     * pada bulan dan tahun periode yang dipilih.
     *
     * SUMBER KEBENARAN:
     *   Siklus = riwayat yang jadwal_target-nya di periode ini.
     *   BUKAN dari tanggal_pengambilan (realisasi boleh di bulan lain).
     *
     * CONTOH BENAR:
     *   pengambilan 03/03/2026, jadwal_target 02/04/2026
     *   → Ini adalah transaksi siklus APRIL (jadwal_target = April)
     *   → Selisih = -30 hari (lebih awal)
     *   → Status = Tidak Patuh
     *   → Tetap menjadi transaksi historis siklus April
     *
     * KEDATANGAN PASIEN (tanggal_realisasi_aktif):
     *   = tanggal_pengambilan dari riwayat siklus periode ini.
     *   BUKAN dari tanggal_pengambilan_terakhir master.
     *
     * TERAKHIR AMBIL OBAT (mode periode tertentu):
     *   = tanggal_pengambilan dari riwayat siklus periode ini.
     *   BUKAN dari tanggal_pengambilan_terakhir master (yang selalu terbaru).
     *
     * EARLY WARNING (mode periode tertentu):
     *   Mencerminkan kondisi siklus periode yang dipilih.
     *   TIDAK menggunakan kondisi siklus terbaru pasien.
     *
     * LANGKAH 1 — Ada riwayat dengan jadwal_target di periode ini:
     *   → Gunakan data riwayat (sudah final/terkonfirmasi).
     *   → Kedatangan Pasien = tanggal_pengambilan dari riwayat itu.
     *   → Status & Early Warning dari kolom status_kepatuhan riwayat.
     *   → TIDAK mengambil kondisi siklus terbaru pasien.
     *
     * LANGKAH 2 — Tidak ada riwayat, tapi jadwal AKTIF master di periode ini:
     *   → Pasien belum mengambil untuk siklus ini.
     *   → Evaluasi live berdasarkan tanggal_evaluasi.
     *   → Kedatangan Pasien = null jika belum ada realisasi.
     *
     * LANGKAH 3 — Tidak ada riwayat & jadwal aktif bukan periode ini:
     *   → Pasien tidak punya siklus pada periode tersebut.
     *   → "Tidak Ada Transaksi".
     */
    public function tentukanStatusKepatuhanPeriode(
        PasienPrb $pasien,
        string $periode, // format YYYY-MM
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi
            ? Carbon::parse($tanggalEvaluasi)->startOfDay()
            : Carbon::today()->startOfDay();

        if ($pasien->status === 'Pasif') {
            return [
                'status'                  => 'Pasif',
                'early_warning'           => 'Pasif',
                'selisih_hari'            => 0,
                'keterangan'              => $pasien->alasan_pasif ?? 'Dikeluarkan dari pemantauan aktif',
                'tindakan'                => '-',
                'jadwal_target_aktif'     => null,
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // ---------------------------------------------------------------
        // LANGKAH 1: Cari riwayat yang jadwal_target-nya di periode ini.
        //
        // KUNCI: jadwal_target menentukan siklus, BUKAN tanggal_pengambilan.
        //
        // Contoh: pengambilan 11/02 untuk jadwal 13/03 → siklus Maret.
        //   tanggal_pengambilan bisa di bulan lain, tapi jadwal_target
        //   menentukan siklus mana yang direalisasikan.
        //
        // PEMILIHAN TRANSAKSI (jika ada >1 riwayat per siklus):
        //   Ambil yang |selisih_hari| paling kecil → transaksi yang paling
        //   logis untuk siklus ini. Hindari mengambil transaksi dengan
        //   selisih ekstrem (>90 hari) yang merupakan data rogue/testing.
        //
        // SAFEGUARD: jika seluruh riwayat untuk siklus ini punya
        //   |selisih| > 90 hari, anggap tidak ada realisasi valid dan
        //   lanjut ke LANGKAH 2 (evaluasi live siklus aktif).
        // ---------------------------------------------------------------
        $MAKS_SELISIH_VALID = 90; // lebih dari ini dianggap anomali

        $riwayatSiklusKoleksi = $pasien->riwayatPengambilanObat
            ->filter(function ($r) use ($periode) {
                if (!$r->jadwal_target || !$r->tanggal_pengambilan) {
                    return false;
                }
                return Carbon::parse($r->jadwal_target)->format('Y-m') === $periode;
            });

        // Dari koleksi siklus ini, pilih yang paling logis:
        // selisih absolut terkecil, dan di antara yang sama kecil, ambil created_at terbaru.
        $riwayatSiklus = $riwayatSiklusKoleksi
            ->filter(function ($r) use ($MAKS_SELISIH_VALID) {
                return abs($r->selisih_hari ?? 0) <= $MAKS_SELISIH_VALID;
            })
            ->sortBy([
                [fn($r) => abs($r->selisih_hari ?? 0), 'asc'],  // selisih terkecil dulu
                [fn($r) => $r->created_at,            'desc'],  // paling baru jika seri
            ])
            ->first();

        if ($riwayatSiklus) {
            // Hitung ulang selisih dan status dari data yang tersimpan
            // untuk memastikan konsistensi dengan aturan H-3/H+3.
            $tglAmbil    = Carbon::parse($riwayatSiklus->tanggal_pengambilan)->startOfDay();
            $jadwalRiwayat = Carbon::parse($riwayatSiklus->jadwal_target)->startOfDay();

            $selisihHari = $riwayatSiklus->selisih_hari ?? (int) $jadwalRiwayat->diffInDays($tglAmbil, false);
            $statusKepatuhan = $riwayatSiklus->status_kepatuhan ?? 'Tidak Patuh';

            // Tentukan Early Warning berdasarkan status dan selisih historis
            if ($statusKepatuhan === 'Patuh') {
                $earlyWarning = 'Patuh';
                $keterangan   = 'Pengambilan obat telah direalisasikan.';
                $tindakan     = 'Tidak perlu tindak lanjut';
            } else {
                // Tidak patuh — bisa terlambat atau terlalu awal
                $earlyWarning = 'Tidak patuh/Overdue';
                $keterangan   = $selisihHari < 0
                    ? abs($selisihHari) . ' hari lebih awal, di luar batas toleransi H-3'
                    : 'Terlambat ' . $selisihHari . ' hari, melewati batas toleransi H+3';
                $tindakan = 'Segera dilaporkan kepada FKTP untuk ditindaklanjuti';
            }

            return [
                'status'                  => $statusKepatuhan,
                'early_warning'           => $earlyWarning,
                'selisih_hari'            => $selisihHari,
                'keterangan'              => $keterangan,
                'tindakan'                => $tindakan,
                'jadwal_target_aktif'     => $jadwalRiwayat->toDateString(),
                // Kedatangan Pasien = tanggal realisasi aktual siklus ini,
                // BUKAN tanggal_pengambilan_terakhir master.
                'tanggal_realisasi_aktif' => $tglAmbil->toDateString(),
                'riwayat_id_aktif'        => $riwayatSiklus->id,
            ];
        }

        // ---------------------------------------------------------------
        // LANGKAH 2: Tidak ada riwayat. Cek jadwal aktif master.
        //
        // Jika tanggal_jadwal_berikutnya jatuh di periode ini → siklus
        // sedang berjalan dan pasien belum mengambil obat.
        //
        // Untuk siklus yang BELUM terealisasi, Kedatangan Pasien = null.
        // Early Warning dievaluasi secara live berdasarkan tanggal_evaluasi
        // relatif terhadap jadwal siklus periode ini — BUKAN kondisi terbaru.
        // ---------------------------------------------------------------
        $jadwalAktif = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->startOfDay()
            : null;

        if ($jadwalAktif && Carbon::parse($jadwalAktif)->format('Y-m') === $periode) {

            $batasAwal  = $jadwalAktif->copy()->subDays(self::TOLERANSI_SEBELUM);
            $batasAkhir = $jadwalAktif->copy()->addDays(self::TOLERANSI_SESUDAH);

            // Cek tanggal_realisasi sementara di master
            // (belum dikonfirmasi ke riwayat, hanya jika relevan untuk siklus ini)
            $tglRealisasiSementara = null;
            if ($pasien->tanggal_realisasi) {
                $parsed = Carbon::parse($pasien->tanggal_realisasi)->startOfDay();
                if ($parsed->between($batasAwal, $batasAkhir, true)) {
                    $tglRealisasiSementara = $parsed;
                }
            }

            if ($tglRealisasiSementara) {
                $selisihRealisasi = (int) $jadwalAktif->diffInDays($tglRealisasiSementara, false);

                if ($tglRealisasiSementara->between($batasAwal, $batasAkhir, true)) {
                    return [
                        'status'                  => 'Patuh',
                        'early_warning'           => 'Patuh',
                        'selisih_hari'            => $selisihRealisasi,
                        'keterangan'              => 'Pengambilan obat telah direalisasikan.',
                        'tindakan'                => 'Tidak perlu tindak lanjut',
                        'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                        'tanggal_realisasi_aktif' => $tglRealisasiSementara->toDateString(),
                    ];
                }

                $keterangan = $selisihRealisasi < 0
                    ? abs($selisihRealisasi) . ' hari lebih awal, di luar batas toleransi H-3'
                    : 'Terlambat ' . $selisihRealisasi . ' hari, melewati batas toleransi H+3';

                return [
                    'status'                  => 'Tidak Patuh',
                    'early_warning'           => 'Tidak patuh/Overdue',
                    'selisih_hari'            => $selisihRealisasi,
                    'keterangan'              => $keterangan,
                    'tindakan'                => 'Segera dilaporkan kepada FKTP untuk ditindaklanjuti',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => $tglRealisasiSementara->toDateString(),
                ];
            }

            // Belum ada realisasi → evaluasi live berdasarkan tanggal_evaluasi
            // Early Warning mencerminkan kondisi SIKLUS PERIODE INI, bukan siklus terbaru.
            $selisih = (int) $jadwalAktif->diffInDays($evaluasi, false);

            if ($evaluasi->lt($batasAwal)) {
                return [
                    'status'                  => 'Belum Jatuh Tempo',
                    'early_warning'           => 'Belum memasuki jadwal',
                    'selisih_hari'            => $selisih,
                    'keterangan'              => 'Belum memasuki periode pengambilan',
                    'tindakan'                => 'Belum perlu tindakan, bisa dilayani',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => null,
                ];
            }

            if ($evaluasi->between($batasAwal, $jadwalAktif->copy()->subDays(1), true)) {
                return [
                    'status'                  => 'Belum Jatuh Tempo',
                    'early_warning'           => 'Jadwal mendekat',
                    'selisih_hari'            => $selisih,
                    'keterangan'              => 'Jadwal pengambilan mendekat',
                    'tindakan'                => 'Reminder kepada pasien',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => null,
                ];
            }

            if ($evaluasi->equalTo($jadwalAktif)) {
                return [
                    'status'                  => 'Belum Diambil',
                    'early_warning'           => 'Jatuh tempo',
                    'selisih_hari'            => $selisih,
                    'keterangan'              => 'Hari ini merupakan tanggal pengambilan obat',
                    'tindakan'                => 'Reminder lebih aktif',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => null,
                ];
            }

            if ($evaluasi->between($jadwalAktif->copy()->addDays(1), $batasAkhir, true)) {
                return [
                    'status'                  => 'Belum Diambil',
                    'early_warning'           => 'Belum mengambil, masih toleransi',
                    'selisih_hari'            => $selisih,
                    'keterangan'              => 'Belum mengambil obat, masih dalam batas toleransi sampai H+3',
                    'tindakan'                => 'Hubungi pasien dan informasikan FKTP',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => null,
                ];
            }

            // H+4 dst — melewati toleransi, belum ada realisasi
            // Ini sudah pasti Tidak Patuh karena periode sudah lewat dan tidak ada pengambilan.
            return [
                'status'                  => 'Tidak Patuh',
                'early_warning'           => 'Tidak patuh/Overdue',
                'selisih_hari'            => $selisih,
                'keterangan'              => 'Terlambat ' . $selisih . ' hari, melewati batas toleransi H+3',
                'tindakan'                => 'Segera dilaporkan kepada FKTP untuk ditindaklanjuti',
                'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // ---------------------------------------------------------------
        // LANGKAH 3: Tidak ada riwayat di periode ini dan jadwal aktif
        //            master bukan di periode ini.
        //            → Pasien tidak memiliki siklus pada periode ini.
        //
        // Contoh: pasien baru bergabung Oktober, user memilih Januari →
        // "Tidak Ada Transaksi" — bukan error, hanya tidak ada data.
        // ---------------------------------------------------------------
        return [
            'status'                  => 'Tidak Ada Transaksi',
            'early_warning'           => '-',
            'selisih_hari'            => 0,
            'keterangan'              => 'Tidak terdapat siklus pengambilan pada periode ini',
            'tindakan'                => '-',
            'jadwal_target_aktif'     => null,
            'tanggal_realisasi_aktif' => null,
        ];
    }

    /**
     * =========================================================
     * PROSES REALISASI PENGAMBILAN OBAT
     * =========================================================
     *
     * Alur (satu transaksi atomik via DB::transaction):
     *   1. Cek duplikat: pasien_prb_id + tanggal_pengambilan.
     *   2. Jika DUPLIKAT → kembalikan array ['duplikat' => true].
     *      Caller wajib menangani ini dan menampilkan pesan yang tepat.
     *      Tidak ada insert, tidak ada update master.
     *   3. Jika BARU:
     *      a. Simpan riwayat dengan jadwal_target siklus SEBELUM dimajukan.
     *      b. Hitung selisih_hari dan status_kepatuhan terhadap jadwal_target.
     *      c. Update master: tanggal_pengambilan_terakhir, jadwal baru,
     *         jenis_prb=Lama, jatah=30, tanggal_realisasi=null.
     *      d. Kembalikan array ['duplikat' => false, 'pasien' => $pasien->fresh()].
     *
     * IDEMPOTENCY:
     *   Cek duplikat menggunakan pasien_prb_id + tanggal_pengambilan,
     *   BUKAN no_faktur. no_faktur internal berfungsi sebagai database
     *   safety (UNIQUE constraint), bukan sebagai sumber idempotency check.
     *   Dengan ini, double-click atau retry tidak akan menyebabkan 500.
     *
     * TRANSACTION SAFETY:
     *   INSERT riwayat dan UPDATE master dibungkus dalam DB::transaction().
     *   Jika INSERT gagal (mis. constraint lain), master tidak akan dimajukan.
     *
     * ATURAN selisih_hari:
     *   positif = terlambat, negatif = lebih awal, nol = tepat waktu
     *
     * ATURAN status_kepatuhan:
     *   H-3 s.d. H+3 = Patuh, di luar itu = Tidak Patuh
     *
     * @return array{duplikat: bool, pasien?: PasienPrb}
     */
    public function prosesRealisasi(
        PasienPrb $pasien,
        string $tanggalRealisasi
    ): array {
        $realisasi = Carbon::parse($tanggalRealisasi)->startOfDay();

        // no_faktur internal bersifat deterministik:
        // satu pasien + satu tanggal = selalu menghasilkan string yang sama.
        // Format: REALISASI-YYYYMMDD-{12 char pertama UUID tanpa strip}
        // 12 karakter dipilih untuk menghindari collision antar UUID pasien
        // yang dibuat bersamaan (8 karakter pertama bisa identik).
        $noFakturInternal = 'REALISASI-'
            . $realisasi->format('Ymd')
            . '-'
            . strtoupper(substr(str_replace('-', '', $pasien->id), 0, 12));

        // ------------------------------------------------------------------
        // LANGKAH 1: Cek duplikat — dua lapis untuk keandalan maksimal.
        //
        // Lapis 1 (utama): cek no_faktur yang deterministik.
        //   Lebih reliable di SQLite karena perbandingan string murni,
        //   tidak bergantung pada format penyimpanan datetime.
        //
        // Lapis 2 (fallback): cek pasien_prb_id + tanggal_pengambilan
        //   dengan LIKE untuk menangkap kasus di mana riwayat tersimpan
        //   dengan no_faktur berbeda (mis. dari import Excel) tapi tanggal
        //   pengambilan sama.
        //
        // Jika salah satu cek menemukan duplikat → return langsung.
        // Tidak ada INSERT, tidak ada UPDATE master.
        // ------------------------------------------------------------------
        $sudahAdaByFaktur = RiwayatPengambilanObat::where('no_faktur', $noFakturInternal)
            ->exists();

        $sudahAdaByTanggal = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
            ->where('tanggal_pengambilan', 'like', $realisasi->toDateString() . '%')
            ->exists();

        if ($sudahAdaByFaktur || $sudahAdaByTanggal) {
            // Realisasi pada tanggal ini sudah tercatat sebelumnya.
            // Jangan INSERT riwayat baru, jangan update master.
            return ['duplikat' => true];
        }

        // ------------------------------------------------------------------
        // LANGKAH 2: Tangkap jadwal_target siklus SEBELUM dimajukan.
        //
        // jadwal_target di riwayat harus mencerminkan siklus yang sedang
        // direalisasikan — bukan siklus berikutnya yang akan dihitung nanti.
        // Ambil sekarang sebelum master diubah.
        // ------------------------------------------------------------------
        $jadwalTarget = $pasien->tanggal_jadwal_berikutnya
            ? Carbon::parse($pasien->tanggal_jadwal_berikutnya)->startOfDay()
            : null;

        // ------------------------------------------------------------------
        // LANGKAH 3: Hitung selisih dan status kepatuhan transaksi ini.
        //
        // Status dihitung sekali dan disimpan ke riwayat — tidak pernah
        // dihitung ulang berdasarkan jadwal baru di kemudian hari.
        // ------------------------------------------------------------------
        $selisihHari     = 0;
        $statusKepatuhan = 'Patuh';

        if ($jadwalTarget) {
            $selisihHari = (int) $jadwalTarget->diffInDays($realisasi, false);

            $batasAwal  = $jadwalTarget->copy()->subDays(self::TOLERANSI_SEBELUM);
            $batasAkhir = $jadwalTarget->copy()->addDays(self::TOLERANSI_SESUDAH);

            $statusKepatuhan = $realisasi->between($batasAwal, $batasAkhir, true)
                ? 'Patuh'
                : 'Tidak Patuh';
        }

        // ------------------------------------------------------------------
        // LANGKAH 4: INSERT riwayat + UPDATE master dalam satu transaksi DB.
        //
        // Jika INSERT gagal karena alasan apapun (termasuk UNIQUE constraint
        // no_faktur yang lolos dari cek di atas karena race condition),
        // DB::transaction() akan rollback sehingga master tidak ikut dimajukan.
        //
        // no_faktur internal sudah dihitung di awal fungsi (deterministik).
        // ------------------------------------------------------------------
        $jadwalBaru = $this->hitungJadwalBerikutnya($realisasi, 30);

        DB::transaction(function () use (
            $pasien, $realisasi, $jadwalTarget,
            $noFakturInternal, $selisihHari, $statusKepatuhan, $jadwalBaru
        ) {
            // 4a. Simpan riwayat — jadwal_target = siklus INI (sebelum dimajukan)
            // tanggal_berobat = null: realisasi manual tidak memiliki tanggal kunjungan
            // apotek yang terpisah — jangan mengisi dengan tanggal_pengambilan.
            RiwayatPengambilanObat::create([
                'pasien_prb_id'       => $pasien->id,
                'no_faktur'           => $noFakturInternal,
                'tanggal_berobat'     => null, // tidak tersedia untuk realisasi manual
                'tanggal_pengambilan' => $realisasi->toDateString(),
                'jadwal_target'       => $jadwalTarget?->toDateString() ?? $realisasi->toDateString(),
                'selisih_hari'        => $selisihHari,
                'status_kepatuhan'    => $statusKepatuhan,
            ]);

            // 4b. Update master pasien — semua field harus sinkron:
            //   tanggal_pengambilan_terakhir = tanggal realisasi (BUKAN jadwal)
            //   tanggal_jadwal_berikutnya    = realisasi + 30 hari
            //   jenis_prb                    = Lama
            //   jatah_obat                   = 30
            //   tanggal_realisasi            = null (reset untuk siklus baru)
            $pasien->update([
                'tanggal_pengambilan_terakhir' => $realisasi->toDateString(),
                'tanggal_jadwal_berikutnya'    => $jadwalBaru->toDateString(),
                'tanggal_realisasi'            => null,
                'jenis_prb'                    => 'Lama',
                'jatah_obat'                   => 30,
            ]);
        });

        return ['duplikat' => false, 'pasien' => $pasien->fresh()];
    }

    /**
     * =========================================================
     * EDIT REALISASI PENGAMBILAN OBAT (KOREKSI TANGGAL)
     * =========================================================
     *
     * Digunakan saat petugas ingin mengoreksi tanggal realisasi yang
     * sudah tercatat sebelumnya. BERBEDA dengan prosesRealisasi():
     *
     *   prosesRealisasi()  → CREATE riwayat baru untuk siklus baru
     *   editRealisasi()    → UPDATE riwayat yang sudah ada
     *
     * Alur:
     *   1. Cari riwayat aktif berdasarkan riwayat_id yang dikirim.
     *      Riwayat harus milik pasien yang sama (validasi kepemilikan).
     *   2. Jika tanggal baru sama dengan yang lama → return ['tidak_berubah' => true].
     *   3. Cek tidak ada riwayat LAIN pada tanggal baru (duplikasi antar siklus).
     *   4. Dalam DB::transaction:
     *      a. UPDATE tanggal_pengambilan, selisih_hari, status_kepatuhan pada riwayat.
     *         jadwal_target TIDAK diubah — tetap jadwal siklus yang seharusnya dipenuhi.
     *         no_faktur TIDAK diubah.
     *      b. Jika riwayat yang diedit adalah riwayat terbaru pasien:
     *         → update tanggal_pengambilan_terakhir & jadwal_berikutnya pada master.
     *   5. Return ['berhasil' => true].
     *
     * ATURAN:
     *   - jadwal_target histori TIDAK berubah.
     *   - no_faktur TIDAK berubah.
     *   - Tidak membuat riwayat duplikat.
     *   - jadwal berikutnya dihitung dari tanggal_pengambilan aktual terbaru.
     *
     * @return array{berhasil?: bool, tidak_berubah?: bool, duplikat?: bool, error?: string}
     */
    public function editRealisasi(
        PasienPrb $pasien,
        string $riwayatId,
        string $tanggalBaruStr
    ): array {
        $tanggalBaru = Carbon::parse($tanggalBaruStr)->startOfDay();

        // Cari riwayat — validasi kepemilikan sekaligus
        $riwayat = RiwayatPengambilanObat::where('id', $riwayatId)
            ->where('pasien_prb_id', $pasien->id)
            ->first();

        if (!$riwayat) {
            return ['error' => 'Riwayat tidak ditemukan atau bukan milik pasien ini.'];
        }

        // Tanggal sama → tidak perlu diubah
        $tanggalLama = Carbon::parse($riwayat->tanggal_pengambilan)->startOfDay();
        if ($tanggalLama->eq($tanggalBaru)) {
            return ['tidak_berubah' => true];
        }

        // Cek duplikat: ada riwayat LAIN (bukan record ini) dengan tanggal yang sama
        $duplikat = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
            ->where('id', '!=', $riwayatId)
            ->where('tanggal_pengambilan', 'like', $tanggalBaru->toDateString() . '%')
            ->exists();

        if ($duplikat) {
            return ['duplikat' => true];
        }

        // Hitung selisih dan status baru berdasarkan jadwal_target yang TIDAK BERUBAH
        $jadwalTarget = Carbon::parse($riwayat->jadwal_target)->startOfDay();
        $selisihBaru  = (int) $jadwalTarget->diffInDays($tanggalBaru, false);

        $batasAwal  = $jadwalTarget->copy()->subDays(self::TOLERANSI_SEBELUM);
        $batasAkhir = $jadwalTarget->copy()->addDays(self::TOLERANSI_SESUDAH);
        $statusBaru = $tanggalBaru->between($batasAwal, $batasAkhir, true)
            ? 'Patuh'
            : 'Tidak Patuh';

        DB::transaction(function () use (
            $pasien, $riwayat, $tanggalBaru, $selisihBaru, $statusBaru
        ) {
            // UPDATE riwayat — hanya tanggal_pengambilan, selisih, dan status.
            // jadwal_target dan no_faktur tetap seperti semula.
            $riwayat->update([
                'tanggal_pengambilan' => $tanggalBaru->toDateString(),
                'selisih_hari'        => $selisihBaru,
                'status_kepatuhan'    => $statusBaru,
            ]);

            // Sinkronkan master pasien jika riwayat yang diedit adalah yang terbaru.
            // Cek: setelah update, apakah tanggal_baru adalah tanggal terbesar dari semua riwayat?
            $terbaru = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
                ->orderBy('tanggal_pengambilan', 'desc')
                ->first();

            if ($terbaru && $terbaru->id === $riwayat->id) {
                // Riwayat ini adalah yang terbaru → sinkronkan master
                $jadwalBaru = $this->hitungJadwalBerikutnya($tanggalBaru, 30);
                $pasien->update([
                    'tanggal_pengambilan_terakhir' => $tanggalBaru->toDateString(),
                    'tanggal_jadwal_berikutnya'    => $jadwalBaru->toDateString(),
                    'jenis_prb'                    => 'Lama',
                    'jatah_obat'                   => 30,
                ]);
            }
        });

        return ['berhasil' => true];
    }

    /**
     * =========================================================
     * HITUNG STATISTIK DASHBOARD
     * =========================================================
     *
     * Statistik kondisi siklus aktif pasien pada tanggal evaluasi.
     */
    public function hitungStatistikDashboard(
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = $tanggalEvaluasi ?: Carbon::today()->toDateString();

        $semuaPasien = PasienPrb::with('riwayatPengambilanObat')->get();

        $totalPasien     = $semuaPasien->count();
        $pasienAktif     = $semuaPasien->where('status', 'Aktif');
        $pasienPasif     = $semuaPasien->where('status', 'Pasif');

        $patuh           = 0;
        $tidakPatuh      = 0;
        $dalamToleransi  = 0;
        $belumJatuhTempo = 0;

        foreach ($pasienAktif as $pasien) {
            $k = $this->tentukanStatusKepatuhan($pasien, $evaluasi);
            switch ($k['status']) {
                case 'Patuh':          $patuh++;           break;
                case 'Tidak Patuh':    $tidakPatuh++;      break;
                case 'Belum Diambil':  $dalamToleransi++;  break;
                case 'Belum Jatuh Tempo': $belumJatuhTempo++; break;
            }
        }

        $totalAktif         = $pasienAktif->count();
        $pasienDapatDinilai = $patuh + $tidakPatuh;
        $persentasePatuh    = $pasienDapatDinilai > 0
            ? round(($patuh / $pasienDapatDinilai) * 100, 1)
            : 0;

        // Statistik per diagnosis
        $perDiagnosis = [];
        foreach (PasienPrb::DIAGNOSIS_OPTIONS as $diagnosis) {
            $diagPatuh      = 0;
            $diagTidakPatuh = 0;

            foreach ($pasienAktif as $p) {
                $diag = $p->diagnosis_prb;
                if (!is_array($diag) || !in_array($diagnosis, $diag, true)) {
                    continue;
                }
                $k = $this->tentukanStatusKepatuhan($p, $evaluasi);
                if ($k['status'] === 'Patuh')       $diagPatuh++;
                if ($k['status'] === 'Tidak Patuh') $diagTidakPatuh++;
            }

            $diagTotal = $diagPatuh + $diagTidakPatuh;
            if ($diagTotal > 0) {
                $perDiagnosis[] = [
                    'diagnosis'   => $diagnosis,
                    'total'       => $diagTotal,
                    'patuh'       => $diagPatuh,
                    'tidak_patuh' => $diagTidakPatuh,
                    'persentase'  => round(($diagPatuh / $diagTotal) * 100, 1),
                ];
            }
        }

        // Statistik per FKTP
        $fktpList = $pasienAktif->pluck('fktp_asal')->filter()->unique();
        $perFktp  = [];

        foreach ($fktpList as $fktp) {
            $fktpPatuh      = 0;
            $fktpTidakPatuh = 0;

            foreach ($pasienAktif->where('fktp_asal', $fktp) as $p) {
                $k = $this->tentukanStatusKepatuhan($p, $evaluasi);
                if ($k['status'] === 'Patuh')       $fktpPatuh++;
                if ($k['status'] === 'Tidak Patuh') $fktpTidakPatuh++;
            }

            $fktpTotal = $fktpPatuh + $fktpTidakPatuh;
            $perFktp[] = [
                'fktp'        => $fktp,
                'total'       => $fktpTotal,
                'patuh'       => $fktpPatuh,
                'tidak_patuh' => $fktpTidakPatuh,
                'persentase'  => $fktpTotal > 0
                    ? round(($fktpPatuh / $fktpTotal) * 100, 1)
                    : 0,
            ];
        }

        return [
            'total_pasien'      => $totalPasien,
            'total_aktif'       => $totalAktif,
            'total_pasif'       => $pasienPasif->count(),
            'patuh'             => $patuh,
            'tidak_patuh'       => $tidakPatuh,
            'dalam_toleransi'   => $dalamToleransi,
            'belum_jatuh_tempo' => $belumJatuhTempo,
            'persentase_patuh'  => $persentasePatuh,
            'per_diagnosis'     => $perDiagnosis,
            'per_fktp'          => $perFktp,
            'tanggal_evaluasi'  => $evaluasi,
        ];
    }
}
