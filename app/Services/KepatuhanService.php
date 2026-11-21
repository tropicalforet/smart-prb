<?php

namespace App\Services;

use App\Models\PasienPrb;
use App\Models\RiwayatPengambilanObat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KepatuhanService
{
    /**
     * =========================================================
     * KONSTANTA ATURAN BISNIS PRB
     * =========================================================
     *
     * Seluruh angka kebijakan klinis dan bisnis BPJS Kesehatan
     * dipusatkan di sini sebagai konstanta bertipe public const.
     *
     * Menggunakan konstanta (bukan literal tersebar) memastikan:
     * - Satu titik perubahan jika kebijakan BPJS berubah.
     * - Tidak ada inkonsistensi antar controller, service, command.
     * - Dokumentasi terpusat tentang asal nilai.
     */

    /**
     * Jatah obat (hari) untuk Pasien PRB Baru.
     * PRB Baru = belum pernah ambil obat sejak terdaftar.
     * Berdasarkan ketentuan BPJS Kesehatan: jadwal pertama = mulai + 23 hari.
     */
    public const JATAH_PRB_BARU = 23;

    /**
     * Jatah obat (hari) untuk Pasien PRB Lama.
     * PRB Lama = sudah memiliki minimal satu riwayat pengambilan.
     * Berdasarkan ketentuan BPJS Kesehatan: jadwal berikutnya = terakhir + 30 hari.
     */
    public const JATAH_PRB_LAMA = 30;

    /**
     * Batas selisih hari maksimum agar riwayat dianggap valid.
     * Riwayat dengan |selisih| > nilai ini dianggap anomali/data rogue
     * dan dikeluarkan dari perhitungan status & EWS.
     */
    public const MAKS_SELISIH_VALID = 90;

    /** Status yang sudah final dinilai — dipakai Rekap Kepatuhan & ekspornya. */
    public const STATUS_DINILAI = ['Patuh', 'Tidak Teratur'];

    /**
     * Toleransi pengambilan obat (hari):
     * H-TOLERANSI_SEBELUM s/d H+TOLERANSI_SESUDAH dihitung Patuh.
     */
    private const TOLERANSI_SEBELUM = 3;
    private const TOLERANSI_SESUDAH = 3;

    /**
     * =========================================================
     * HELPER: TENTUKAN JENIS PRB (BARU / LAMA)
     * =========================================================
     *
     * Ditentukan dari BULAN mulai PRB dibandingkan BULAN acuan:
     *   PRB BARU  = mulai PRB di bulan acuan (mis. mulai Oktober,
     *               ambil obat Oktober) → jadwal berikutnya +23 hari.
     *   PRB LAMA  = mulai PRB sebelum bulan acuan → jadwal berikutnya +30 hari.
     *
     * Bulan acuan = bulan pengambilan obat (bukan hari ini), agar data
     * Oktober yang baru di-import November tetap dinilai dengan benar.
     * Tanpa pengambilan, acuan = hari ini.
     *
     * Tanpa tanggal mulai PRB → Lama, KECUALI pasien yang pertama kali ambil
     * obat di bulan terakhir periode penelitian (arahan pembimbing: data
     * Maret–Mei, "yang baru ambil Mei dan tidak ada riwayat Maret/April
     * dikatakan Baru"). Untuk pasien itu, pengambilan pertama = mulai PRB.
     *
     * Contoh: mulai 01/09/2026, ambil 01/10/2026 → Lama → jadwal 31/10/2026.
     *
     * @param string|null $tanggalMulaiPrb      'Y-m-d'
     * @param string|null $tanggalAcuan         tanggal pengambilan obat; null = hari ini
     * @param string|null $tanggalAmbilPertama  pengambilan pertama pasien (lihat tanggalAmbilPertama())
     * @return string  'Baru' atau 'Lama'
     */
    public static function tentukanJenisPrb(
        ?string $tanggalMulaiPrb,
        ?string $tanggalAcuan = null,
        ?string $tanggalAmbilPertama = null
    ): string {
        if (empty($tanggalMulaiPrb)) {
            $bulanBaru = self::bulanPrbBaruTanpaMulai();
            if (!$bulanBaru || empty($tanggalAmbilPertama)
                || Carbon::parse($tanggalAmbilPertama)->format('Y-m') !== $bulanBaru) {
                return 'Lama';
            }
            $tanggalMulaiPrb = $tanggalAmbilPertama;
        }

        $bulanMulai = Carbon::parse($tanggalMulaiPrb)->format('Y-m');
        $bulanAcuan = Carbon::parse($tanggalAcuan ?: Carbon::today())->format('Y-m');

        return $bulanMulai < $bulanAcuan ? 'Lama' : 'Baru';
    }

    /** Bulan data penelitian (YYYY-MM, urut naik); kosong = semua bulan. */
    public static function periodePenelitian(): array
    {
        $periode = array_values(array_filter((array) config('prb.periode_penelitian', [])));
        sort($periode);

        return $periode;
    }

    /**
     * Tanggal evaluasi bawaan (Y-m-d): akhir periode penelitian, mis.
     * 31/05/2026 — data penelitian berhenti di sana, jadi evaluasi "hari ini"
     * membuat semua pasien tampak Overdue. Jika hari ini masih lebih awal,
     * atau periode tidak dibatasi, pakai hari ini. Tetap bisa diganti manual.
     */
    public static function tanggalEvaluasiBawaan(): string
    {
        $hariIni = Carbon::today()->toDateString();
        $periode = self::periodePenelitian();

        if (!$periode) {
            return $hariIni;
        }

        $akhirPenelitian = Carbon::parse(end($periode) . '-01')->endOfMonth()->toDateString();

        return min($hariIni, $akhirPenelitian);
    }

    /** "Maret–Mei 2026" (null jika periode tidak dibatasi). */
    public static function labelPeriodePenelitian(): ?string
    {
        $periode = self::periodePenelitian();
        if (!$periode) {
            return null;
        }

        $awal  = Carbon::parse(reset($periode) . '-01');
        $akhir = Carbon::parse(end($periode) . '-01');

        return $awal->year === $akhir->year
            ? $awal->translatedFormat('F') . '–' . $akhir->translatedFormat('F Y')
            : $awal->translatedFormat('F Y') . '–' . $akhir->translatedFormat('F Y');
    }

    /** Apakah tanggal (Y-m-d...) jatuh di periode penelitian (selalu true jika tidak dibatasi). */
    public static function dalamPeriodePenelitian(?string $tanggal): bool
    {
        $periode = self::periodePenelitian();

        return empty($periode) || ($tanggal !== null && in_array(substr($tanggal, 0, 7), $periode, true));
    }

    /** Bulan terakhir penelitian — pasien tanpa mulai PRB yang pertama ambil di bulan ini = Baru. */
    private static function bulanPrbBaruTanpaMulai(): ?string
    {
        $periode = self::periodePenelitian();

        return $periode ? end($periode) : null;
    }

    /**
     * Tanggal pengambilan pertama pasien: yang paling awal antara riwayat
     * tersimpan dan $kandidat (pengambilan yang sedang dicatat).
     */
    public static function tanggalAmbilPertama(?PasienPrb $pasien, ?string $kandidat = null): ?string
    {
        $tersimpan = $pasien?->exists
            ? $pasien->riwayatPengambilanObat()->whereNotNull('tanggal_pengambilan')->min('tanggal_pengambilan')
            : null;

        $semua = array_filter([
            $tersimpan ? substr((string) $tersimpan, 0, 10) : null,
            $kandidat ? Carbon::parse($kandidat)->toDateString() : null,
        ]);

        return $semua ? min($semua) : null;
    }

    /**
     * =========================================================
     * HELPER: CEK APAKAH PASIEN BELUM BERGABUNG DI PERIODE INI
     * =========================================================
     *
     * Bulan evaluasi dibandingkan dengan bulan pertama pasien terdaftar
     * di program PRB (tanggal_mulai_prb). Jika bulan evaluasi lebih awal
     * dari bulan mulai PRB, pasien belum bergabung dan tidak boleh dinilai.
     *
     * Perbandingan dilakukan di level BULAN (Y-m), bukan hari, karena
     * aturan kepatuhan bekerja per siklus bulanan. Pasien yang mulai PRB
     * tanggal 25 September tetap dihitung mulai bergabung di bulan September
     * — tidak perlu menunggu bulan Oktober.
     *
     * DIGUNAKAN OLEH:
     *   - tentukanStatusKepatuhan()        → mode semua periode
     *   - tentukanStatusKepatuhanPeriode() → mode periode tertentu
     *
     * RETURN VALUE:
     *   true  → bulan evaluasi < bulan mulai PRB → kembalikan 'Tidak Ada Transaksi'
     *   false → bulan evaluasi >= bulan mulai PRB → lanjutkan evaluasi normal
     *
     * @param PasienPrb   $pasien
     * @param Carbon      $evaluasi  tanggal evaluasi (sudah di-startOfDay())
     */
    private function isBelumBergabung(PasienPrb $pasien, Carbon $evaluasi): bool
    {
        if (!$pasien->tanggal_mulai_prb) {
            // Tidak ada tanggal mulai PRB → tidak bisa menentukan titik nol,
            // anggap sudah bergabung agar tidak salah mengecualikan.
            return false;
        }

        $bulanMulai   = Carbon::parse($pasien->tanggal_mulai_prb)->startOfMonth();
        $bulanEvaluasi = $evaluasi->copy()->startOfMonth();

        // true jika bulan evaluasi SEBELUM bulan mulai PRB
        return $bulanEvaluasi->lt($bulanMulai);
    }

    /**
     * =========================================================
     * DAFTAR PERIODE (YYYY-MM) UNTUK DROPDOWN FILTER
     * =========================================================
     *
     * 2 sumber (TIDAK dari tanggal_pengambilan riwayat):
     * 1. jadwal_target di riwayat — siklus ditentukan dari jadwal,
     *    bukan dari kapan pasien realisasi mengambil obat.
     *    Contoh: pengambilan 03/03 untuk jadwal 02/04 → siklus April.
     * 2. tanggal_jadwal_berikutnya di master (pasien Aktif) — siklus
     *    yang sedang berjalan dan belum ada riwayatnya.
     *
     * DISTINCT dihitung di database (substr 7 karakter pertama tanggal
     * ISO) agar tidak perlu memuat & mem-parse setiap baris riwayat.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function daftarPeriode(): array
    {
        // Periode penelitian ditetapkan → hanya bulan-bulan itu (terbaru dulu)
        if ($penelitian = self::periodePenelitian()) {
            return collect($penelitian)
                ->sortDesc()
                ->map(fn ($p) => [
                    'value' => $p,
                    'label' => Carbon::parse($p . '-01')->translatedFormat('F Y'),
                ])
                ->values()
                ->toArray();
        }

        $dariRiwayat = RiwayatPengambilanObat::query()
            ->whereNotNull('jadwal_target')
            ->distinct()
            ->pluck(DB::raw('substr(jadwal_target, 1, 7)'));

        $dariMaster = PasienPrb::query()
            ->where('status', 'Aktif')
            ->whereNotNull('tanggal_jadwal_berikutnya')
            ->distinct()
            ->pluck(DB::raw('substr(tanggal_jadwal_berikutnya, 1, 7)'));

        return $dariRiwayat
            ->merge($dariMaster)
            ->filter(fn ($p) => is_string($p) && preg_match('/^\d{4}-\d{2}$/', $p))
            ->unique()
            ->sortDesc()
            ->map(fn ($p) => [
                'value' => $p,
                'label' => Carbon::parse($p . '-01')->translatedFormat('F Y'),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Persentase kepatuhan = Patuh / (Patuh + Tidak Teratur) × 100.
     *
     * Hanya pasien yang sudah dapat dinilai yang masuk penyebut —
     * Dalam Toleransi, Belum Jatuh Tempo, Pasif, dan Tidak Ada Transaksi
     * belum/tidak dinilai. Mengembalikan null bila belum ada yang dinilai.
     * Dipakai Dashboard dan Rekap Kepatuhan agar angkanya selalu sama.
     */
    /** Keterangan untuk pasien yang tidak lagi dipantau (Pasif / Meninggal). */
    private static function keteranganNonAktif(PasienPrb $pasien): string
    {
        if ($pasien->status === 'Meninggal') {
            return 'Pasien meninggal dunia';
        }

        return $pasien->alasan_pasif ?? 'Dikeluarkan dari pemantauan aktif';
    }

    public static function persentasePatuh(int $patuh, int $tidakPatuh): ?float
    {
        $dinilai = $patuh + $tidakPatuh;

        return $dinilai > 0 ? round(($patuh / $dinilai) * 100, 1) : null;
    }

    /**
     * Ambil kolom tanggal sebagai string 'Y-m-d' tanpa membuat objek Carbon.
     *
     * Akses atribut ber-cast 'date' di Eloquent membuat Carbon baru di
     * SETIAP akses; untuk ribuan riwayat itu mendominasi waktu halaman.
     * Nilai mentah dari DB sudah ISO ('Y-m-d' atau 'Y-m-d H:i:s') sehingga
     * bisa dibandingkan/di-substr langsung. Format lain → fallback Carbon.
     */
    private static function tanggalIso(\Illuminate\Database\Eloquent\Model $model, string $kolom): ?string
    {
        $raw = $model->getAttributes()[$kolom] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }

        return Carbon::parse($raw)->toDateString();
    }

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
     *   ≥ 4    → Tidak teratur/Overdue
     *
     * ── B. STATUS dari riwayat terbaru ──
     *   Ada riwayat → status = status_kepatuhan riwayat terbaru
     *   Tidak ada riwayat → status diturunkan dari EWS
     *
     * Kombinasi VALID:
     *   EWS=Belum memasuki jadwal + Status=Patuh     → riwayat Patuh + jadwal belum tiba
     *   EWS=Belum memasuki jadwal + Status=Tidak Teratur → riwayat Tidak Teratur + jadwal belum tiba
     *   EWS=Jatuh tempo           + Status=Patuh     → riwayat Patuh + hari ini = jadwal aktif
     */
    public function tentukanStatusKepatuhan(
        PasienPrb $pasien,
        ?string $tanggalEvaluasi = null
    ): array {
        $evaluasi = Carbon::parse($tanggalEvaluasi ?: self::tanggalEvaluasiBawaan())->startOfDay();

        // ── Pasif / Meninggal: keluar dari pemantauan ─────────────────────
        if ($pasien->status !== 'Aktif') {
            return [
                'status'                  => $pasien->status,
                'early_warning'           => $pasien->status,
                'selisih_hari'            => 0,
                'keterangan'              => self::keteranganNonAktif($pasien),
                'tindakan'                => '-',
                'jadwal_target_aktif'     => null,
                'tanggal_realisasi_aktif' => null,
                'riwayat_id_aktif'        => null,
            ];
        }

        // ── Pengecualian pasien baru: bulan evaluasi < bulan mulai PRB ────
        //
        // Jika tanggal evaluasi jatuh di bulan sebelum pasien resmi terdaftar
        // di program PRB, pasien ini belum memiliki kewajiban pengambilan obat
        // pada periode tersebut. Sistem TIDAK boleh melabelinya "Tidak Teratur".
        //
        // Contoh kasus:
        //   - Pasien daftar September 2026.
        //   - Dashboard dievaluasi untuk Agustus 2026.
        //   - Pasien dikecualikan → "Tidak Ada Transaksi", bukan "Tidak Teratur".
        //
        // Mode "Semua Periode" (tanpa filter periode spesifik) menggunakan
        // tanggal evaluasi yang dikirim oleh controller (biasanya hari ini).
        // Guard ini memastikan pasien baru yang jadwal pertamanya belum tiba
        // tidak ikut merusak angka kepatuhan global.
        if ($this->isBelumBergabung($pasien, $evaluasi)) {
            return [
                'status'                  => 'Tidak Ada Transaksi',
                'early_warning'           => '-',
                'selisih_hari'            => 0,
                'keterangan'              => 'Pasien belum bergabung pada periode evaluasi ini',
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
        //
        // Perbandingan memakai string ISO mentah (lihat tanggalIso()) —
        // jauh lebih cepat daripada membuat objek Carbon per akses atribut.
        $riwayatTerbaru = $pasien->riwayatPengambilanObat
            ->filter(fn($r) => self::tanggalIso($r, 'tanggal_pengambilan') !== null
                && self::tanggalIso($r, 'jadwal_target') !== null
                && abs($r->selisih_hari ?? 0) <= self::MAKS_SELISIH_VALID)
            ->sortByDesc(fn($r) => self::tanggalIso($r, 'tanggal_pengambilan'))
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
                $earlyWarning  = 'Tidak teratur/Overdue';
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
            $statusHistoris  = $riwayatTerbaru->status_kepatuhan ?? 'Tidak Teratur';

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
                => 'Dalam Toleransi',
            default   // Tidak teratur/Overdue
                => 'Tidak Teratur',
        };

        $tindakanTanpaRiwayat = match($earlyWarning) {
            'Tidak teratur/Overdue'                  => 'Segera laporkan ke FKTP',
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
     *   → Status = Tidak Teratur
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
        $evaluasi = Carbon::parse($tanggalEvaluasi ?: self::tanggalEvaluasiBawaan())->startOfDay();

        if ($pasien->status !== 'Aktif') {
            return [
                'status'                  => $pasien->status,
                'early_warning'           => $pasien->status,
                'selisih_hari'            => 0,
                'keterangan'              => self::keteranganNonAktif($pasien),
                'tindakan'                => '-',
                'jadwal_target_aktif'     => null,
                'tanggal_realisasi_aktif' => null,
            ];
        }

        // ── Pengecualian pasien baru: bulan periode < bulan mulai PRB ─────
        //
        // Pada mode periode tertentu, yang dibandingkan adalah BULAN PERIODE
        // yang sedang dievaluasi (bukan tanggal hari ini).
        //
        // Contoh kasus (dari rekaman):
        //   - Evaluasi rentang Agustus–Oktober 2026.
        //   - Pasien baru daftar September 2026.
        //   - Periode Agustus → isBelumBergabung = true → "Tidak Ada Transaksi".
        //   - Periode September & Oktober → isBelumBergabung = false → evaluasi normal.
        //
        // Contoh kasus (Maret–Mei):
        //   - Pasien baru daftar Mei 2026 (jadwal pertama = Mei).
        //   - Periode Maret & April → "Tidak Ada Transaksi" (bukan "Tidak Teratur").
        //   - Periode Mei → evaluasi normal.
        //
        // Kita pass Carbon dari awal bulan periode (bukan $evaluasi hari ini)
        // agar perbandingan tepat di level bulan, konsisten dengan helper.
        $bulanPeriode = Carbon::parse($periode . '-01')->startOfDay();
        if ($this->isBelumBergabung($pasien, $bulanPeriode)) {
            return [
                'status'                  => 'Tidak Ada Transaksi',
                'early_warning'           => '-',
                'selisih_hari'            => 0,
                'keterangan'              => 'Pasien belum bergabung pada periode ini',
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
        //   logis untuk siklus ini.
        //
        // PENTING: Selisih ekstrem (mis. 698 hari) BUKAN berarti data rogue.
        //   Itu berarti pasien sangat telat untuk siklus ini → status Tidak Teratur.
        //   Jangan buang riwayat hanya karena selisihnya besar.
        //   MAKS_SELISIH_VALID hanya digunakan di mode Semua Periode untuk
        //   menyaring data anomali tanpa konteks siklus spesifik.
        //   Di sini kita sudah punya konteks eksplisit (periode), jadi
        //   semua riwayat dengan jadwal_target di periode ini adalah valid.
        $riwayatSiklusKoleksi = $pasien->riwayatPengambilanObat
            ->filter(function ($r) use ($periode) {
                $jadwal = self::tanggalIso($r, 'jadwal_target');
                if ($jadwal === null || self::tanggalIso($r, 'tanggal_pengambilan') === null) {
                    return false;
                }
                return substr($jadwal, 0, 7) === $periode;
            });

        // Dari koleksi siklus ini, pilih yang paling logis:
        // selisih absolut terkecil, dan di antara yang sama kecil, ambil created_at terbaru.
        // TIDAK ada filter by MAKS_SELISIH_VALID di sini — semua riwayat
        // yang jadwal_target-nya cocok dengan periode adalah kandidat sah.
        //
        // CATATAN: callable di sortBy([...]) dipanggil sebagai PEMBANDING
        // dua argumen ($a, $b) dan harus mengembalikan -1/0/1 — bukan
        // pengambil nilai satu item.
        $riwayatSiklus = $riwayatSiklusKoleksi
            ->sortBy([
                // selisih terkecil dulu
                fn($a, $b) => abs($a->selisih_hari ?? 0) <=> abs($b->selisih_hari ?? 0),
                // paling baru jika seri
                fn($a, $b) => ($b->created_at?->getTimestamp() ?? 0) <=> ($a->created_at?->getTimestamp() ?? 0),
            ])
            ->first();

        if ($riwayatSiklus) {
            // Hitung ulang selisih dan status dari data yang tersimpan
            // untuk memastikan konsistensi dengan aturan H-3/H+3.
            $tglAmbil    = Carbon::parse($riwayatSiklus->tanggal_pengambilan)->startOfDay();
            $jadwalRiwayat = Carbon::parse($riwayatSiklus->jadwal_target)->startOfDay();

            $selisihHari = $riwayatSiklus->selisih_hari ?? (int) $jadwalRiwayat->diffInDays($tglAmbil, false);
            $statusKepatuhan = $riwayatSiklus->status_kepatuhan ?? 'Tidak Teratur';

            // Tentukan Early Warning berdasarkan status dan selisih historis
            if ($statusKepatuhan === 'Patuh') {
                $earlyWarning = 'Patuh';
                $keterangan   = 'Pengambilan obat telah direalisasikan.';
                $tindakan     = 'Tidak perlu tindak lanjut';
            } else {
                // Tidak teratur — bisa terlambat atau terlalu awal
                $earlyWarning = 'Tidak teratur/Overdue';
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
                    'status'                  => 'Tidak Teratur',
                    'early_warning'           => 'Tidak teratur/Overdue',
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
                    'status'                  => 'Dalam Toleransi',
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
                    'status'                  => 'Dalam Toleransi',
                    'early_warning'           => 'Belum mengambil, masih toleransi',
                    'selisih_hari'            => $selisih,
                    'keterangan'              => 'Belum mengambil obat, masih dalam batas toleransi sampai H+3',
                    'tindakan'                => 'Hubungi pasien dan informasikan FKTP',
                    'jadwal_target_aktif'     => $jadwalAktif->toDateString(),
                    'tanggal_realisasi_aktif' => null,
                ];
            }

            // H+4 dst — melewati toleransi, belum ada realisasi
            // Ini sudah pasti Tidak Teratur karena periode sudah lewat dan tidak ada pengambilan.
            return [
                'status'                  => 'Tidak Teratur',
                'early_warning'           => 'Tidak teratur/Overdue',
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
     *         jenis_prb dari tentukanJenisPrb(), jatah sesuai jenis, tanggal_realisasi=null.
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
     *   H-3 s.d. H+3 = Patuh, di luar itu = Tidak Teratur
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
        // LANGKAH 1: Cek duplikat — pasien_prb_id + tanggal_pengambilan.
        //
        // Duplikat ditentukan dari TANGGAL, bukan no_faktur: riwayat yang
        // tanggalnya sudah dikoreksi (editRealisasi) tetap memakai no_faktur
        // lama yang memuat tanggal lama. Mengecek no_faktur akan salah
        // menolak realisasi baru di tanggal lama tersebut.
        //
        // Jika duplikat → return langsung. Tidak ada INSERT/UPDATE.
        // ------------------------------------------------------------------
        $sudahAdaByTanggal = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
            ->where('tanggal_pengambilan', 'like', $realisasi->toDateString() . '%')
            ->exists();

        if ($sudahAdaByTanggal) {
            return ['duplikat' => true];
        }

        // no_faktur wajib unik (UNIQUE constraint). Bila sudah dipakai riwayat
        // yang tanggalnya dikoreksi, beri akhiran -2, -3, ...
        $kandidat = $noFakturInternal;
        for ($n = 2; RiwayatPengambilanObat::where('no_faktur', $kandidat)->exists(); $n++) {
            $kandidat = $noFakturInternal . '-' . $n;
        }
        $noFakturInternal = $kandidat;

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
                : 'Tidak Teratur';
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
        DB::transaction(function () use (
            $pasien, $realisasi, $jadwalTarget,
            $noFakturInternal, $selisihHari, $statusKepatuhan
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

            // Jenis PRB dari bulan mulai PRB vs bulan realisasi.
            $jenisPrbBaru  = self::tentukanJenisPrb(
                $pasien->tanggal_mulai_prb?->toDateString(),
                $realisasi->toDateString(),
                self::tanggalAmbilPertama($pasien, $realisasi->toDateString())
            );
            $jatahObatBaru = $jenisPrbBaru === 'Baru' ? self::JATAH_PRB_BARU : self::JATAH_PRB_LAMA;
            $jadwalBaru    = $this->hitungJadwalBerikutnya($realisasi, $jatahObatBaru);

            // 4b. Update master pasien — semua field harus sinkron:
            //   tanggal_pengambilan_terakhir = tanggal realisasi (BUKAN jadwal)
            //   tanggal_jadwal_berikutnya    = realisasi + jatah hari (Baru=23, Lama=30)
            //   jenis_prb                    = dari bulan mulai PRB vs bulan realisasi
            //   jatah_obat                   = sesuai jenis_prb
            //   tanggal_realisasi            = null (reset untuk siklus baru)
            $pasien->update([
                'tanggal_pengambilan_terakhir' => $realisasi->toDateString(),
                'tanggal_jadwal_berikutnya'    => $jadwalBaru->toDateString(),
                'tanggal_realisasi'            => null,
                'jenis_prb'                    => $jenisPrbBaru,
                'jatah_obat'                   => $jatahObatBaru,
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
            : 'Tidak Teratur';

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
                // Riwayat ini adalah yang terbaru → sinkronkan master.
                // Jenis PRB dari bulan mulai PRB vs bulan tanggal baru.
                $jenisPrbEdit  = self::tentukanJenisPrb(
                    $pasien->tanggal_mulai_prb?->toDateString(),
                    $tanggalBaru->toDateString(),
                    self::tanggalAmbilPertama($pasien, $tanggalBaru->toDateString())
                );
                $jatahObatEdit = $jenisPrbEdit === 'Baru' ? self::JATAH_PRB_BARU : self::JATAH_PRB_LAMA;
                $jadwalBaru    = $this->hitungJadwalBerikutnya($tanggalBaru, $jatahObatEdit);

                $pasien->update([
                    'tanggal_pengambilan_terakhir' => $tanggalBaru->toDateString(),
                    'tanggal_jadwal_berikutnya'    => $jadwalBaru->toDateString(),
                    'jenis_prb'                    => $jenisPrbEdit,
                    'jatah_obat'                   => $jatahObatEdit,
                ]);
            }
        });

        return ['berhasil' => true];
    }

    /**
     * =========================================================
     * HAPUS REALISASI PENGAMBILAN OBAT (SALAH INPUT)
     * =========================================================
     *
     * Untuk realisasi yang tercatat ganda/keliru (mis. tindak lanjut
     * "Sudah mengambil obat" diinput dua kali). Aturan:
     *   - Hanya realisasi manual (no_faktur REALISASI-…); data dari impor
     *     Excel dikoreksi lewat file-nya.
     *   - Hanya pengambilan yang TERAKHIR DICATAT, agar jadwal & status
     *     siklus sesudahnya tidak ikut salah. Yang lebih lama dihapus
     *     berurutan dari yang terakhir dicatat.
     * Sesudah dihapus, master disinkronkan seperti editRealisasi():
     *   - Ada pengambilan sebelumnya → terakhir ambil = tanggal itu, jadwal
     *     berikutnya = tanggal itu + jatah obat.
     *   - Tidak ada → terakhir ambil kosong, jadwal kembali ke jadwal yang
     *     dipenuhi realisasi yang dihapus.
     *
     * @return array{berhasil?: bool, riwayat?: RiwayatPengambilanObat, error?: string}
     */
    public function hapusRealisasi(PasienPrb $pasien, string $riwayatId): array
    {
        $riwayat = RiwayatPengambilanObat::where('id', $riwayatId)
            ->where('pasien_prb_id', $pasien->id)
            ->first();

        if (!$riwayat) {
            return ['error' => 'Riwayat tidak ditemukan atau bukan milik pasien ini.'];
        }
        if (!str_starts_with((string) $riwayat->no_faktur, 'REALISASI-')) {
            return ['error' => 'Pengambilan dari impor Excel tidak dapat dihapus di sini — perbaiki lewat file Excel-nya.'];
        }

        // "Terbaru" = yang terakhir DICATAT (bukan tanggal terbesar): jadwal
        // tiap realisasi dihitung dari realisasi yang dicatat sebelumnya, jadi
        // hanya catatan terakhir yang aman dihapus (UUID v7 urut waktu = penentu seri).
        $terbaru = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();
        if ($terbaru->id !== $riwayat->id) {
            return ['error' => 'Hanya pengambilan yang terakhir dicatat yang dapat dihapus. Hapus dulu yang dicatat sesudahnya.'];
        }

        DB::transaction(function () use ($pasien, $riwayat) {
            $riwayat->delete();

            $sebelumnya = RiwayatPengambilanObat::where('pasien_prb_id', $pasien->id)
                ->orderByDesc('tanggal_pengambilan')
                ->first();

            if ($sebelumnya) {
                $tanggal  = Carbon::parse($sebelumnya->tanggal_pengambilan)->startOfDay();
                $jenisPrb = self::tentukanJenisPrb(
                    $pasien->tanggal_mulai_prb?->toDateString(),
                    $tanggal->toDateString(),
                    self::tanggalAmbilPertama($pasien, $tanggal->toDateString())
                );
                $jatah = $jenisPrb === 'Baru' ? self::JATAH_PRB_BARU : self::JATAH_PRB_LAMA;

                $pasien->update([
                    'tanggal_pengambilan_terakhir' => $tanggal->toDateString(),
                    'tanggal_jadwal_berikutnya'    => $this->hitungJadwalBerikutnya($tanggal, $jatah)->toDateString(),
                    'tanggal_realisasi'            => null,
                    'jenis_prb'                    => $jenisPrb,
                    'jatah_obat'                   => $jatah,
                ]);
            } else {
                $pasien->update([
                    'tanggal_pengambilan_terakhir' => null,
                    'tanggal_jadwal_berikutnya'    => $riwayat->jadwal_target?->toDateString(),
                    'tanggal_realisasi'            => null,
                ]);
            }
        });

        return ['berhasil' => true, 'riwayat' => $riwayat];
    }

    /**
     * =========================================================
     * HITUNG STATISTIK DASHBOARD
     * =========================================================
     *
     * Statistik kondisi siklus aktif pasien pada tanggal evaluasi.
     *
     * PENTING — kalkulasi status hanya dilakukan SEKALI per pasien
     * (bukan tiga kali seperti sebelumnya: global + per_diagnosis + per_fktp).
     * Hasil kalkulasi disimpan dalam map keyed by pasien id, lalu
     * di-aggregate untuk global, per_diagnosis, dan per_fktp.
     *
     * Per_diagnosis menggunakan diagnosis AKTUAL dari data pasien,
     * BUKAN dari DIAGNOSIS_OPTIONS yang hardcoded — sehingga diagnosis
     * bebas dari import Excel (mis. "Gagal Jantung") tetap terhitung.
     */
    public function hitungStatistikDashboard(
        ?string $tanggalEvaluasi = null,
        ?string $filterFktp = null,
        ?string $filterPeriode = null
    ): array {
        $evaluasi = $tanggalEvaluasi ?: self::tanggalEvaluasiBawaan();

        $query = PasienPrb::with([
            'riwayatPengambilanObat' => fn($q) => $q->orderBy('tanggal_pengambilan', 'desc'),
        ]);

        if ($filterFktp) {
            $query->where('fktp_asal', $filterFktp);
        }

        if ($filterPeriode) {
            $query->where(function ($q) use ($filterPeriode) {
                $q->whereHas('riwayatPengambilanObat', function ($qr) use ($filterPeriode) {
                    $qr->where('jadwal_target', 'like', $filterPeriode . '%');
                })->orWhere('tanggal_jadwal_berikutnya', 'like', $filterPeriode . '%');
            });
        }

        $semuaPasien = $query->get();

        // Total Pasien = orang (NOKA unik); satu pasien bisa punya beberapa
        // data PRB (satu per diagnosis). Kepatuhan tetap dinilai per diagnosis.
        $totalPasien = PasienPrb::jumlahPasienUnik($semuaPasien);
        $pasienAktif = $semuaPasien->where('status', 'Aktif');
        $pasienPasif = $semuaPasien->where('status', 'Pasif');
        $totalAktif  = $pasienAktif->count();

        // ── Langkah 1: Hitung status SEKALI per pasien aktif ──────────────
        // Simpan hasilnya dalam array agar per_diagnosis dan per_fktp
        // dapat menggunakannya tanpa memanggil service lagi.
        $statusMap = []; // pasien->id => ['status' => ..., 'diagnosis' => [...], 'fktp' => ...]

        $patuh           = 0;
        $tidakPatuh      = 0;
        $dalamToleransi  = 0;
        $belumJatuhTempo = 0;

        foreach ($pasienAktif as $pasien) {
            $k = $filterPeriode
                ? $this->tentukanStatusKepatuhanPeriode($pasien, $filterPeriode, $evaluasi)
                : $this->tentukanStatusKepatuhan($pasien, $evaluasi);
            $status = $k['status'];

            $statusMap[$pasien->id] = [
                'status'    => $status,
                'diagnosis' => is_array($pasien->diagnosis_prb) ? $pasien->diagnosis_prb : [],
                'fktp'      => $pasien->fktp_asal,
            ];

            switch ($status) {
                case 'Patuh':             $patuh++;           break;
                case 'Tidak Teratur':       $tidakPatuh++;      break;
                case 'Dalam Toleransi':     $dalamToleransi++;  break;
                case 'Belum Jatuh Tempo': $belumJatuhTempo++; break;
            }
        }

        $persentasePatuh = self::persentasePatuh($patuh, $tidakPatuh) ?? 0;

        // ── Langkah 2: Aggregate per diagnosis dari data aktual ───────────
        // Gunakan diagnosis yang benar-benar ada di data, bukan DIAGNOSIS_OPTIONS.
        // Satu pasien bisa punya >1 diagnosis — masing-masing dihitung terpisah.
        $diagMap = []; // 'Hipertensi' => ['patuh' => 3, 'tidak_patuh' => 5]

        foreach ($statusMap as $entry) {
            foreach ($entry['diagnosis'] as $diag) {
                $diag = trim($diag);
                if (!$diag) continue;
                if (!isset($diagMap[$diag])) {
                    $diagMap[$diag] = ['patuh' => 0, 'tidak_patuh' => 0];
                }
                if ($entry['status'] === 'Patuh')       $diagMap[$diag]['patuh']++;
                if ($entry['status'] === 'Tidak Teratur') $diagMap[$diag]['tidak_patuh']++;
            }
        }

        $perDiagnosis = [];
        foreach ($diagMap as $diagnosis => $counts) {
            $diagTotal = $counts['patuh'] + $counts['tidak_patuh'];
            if ($diagTotal === 0) continue;
            $perDiagnosis[] = [
                'diagnosis'   => $diagnosis,
                'total'       => $diagTotal,
                'patuh'       => $counts['patuh'],
                'tidak_patuh' => $counts['tidak_patuh'],
                'persentase'  => round(($counts['patuh'] / $diagTotal) * 100, 1),
            ];
        }
        // Urutkan: diagnosis dengan total terbanyak dulu
        usort($perDiagnosis, fn($a, $b) => $b['total'] <=> $a['total']);

        // ── Langkah 3: Aggregate per FKTP ─────────────────────────────────
        $fktpMap = []; // 'Puskesmas X' => ['patuh' => ..., 'tidak_patuh' => ...]

        foreach ($statusMap as $entry) {
            $fktp = $entry['fktp'] ?: 'Tanpa FKTP';
            if (!isset($fktpMap[$fktp])) {
                $fktpMap[$fktp] = ['patuh' => 0, 'tidak_patuh' => 0];
            }
            if ($entry['status'] === 'Patuh')       $fktpMap[$fktp]['patuh']++;
            if ($entry['status'] === 'Tidak Teratur') $fktpMap[$fktp]['tidak_patuh']++;
        }

        $perFktp = [];
        foreach ($fktpMap as $fktp => $counts) {
            $fktpTotal = $counts['patuh'] + $counts['tidak_patuh'];
            $perFktp[] = [
                'fktp'        => $fktp,
                'total'       => $fktpTotal,
                'patuh'       => $counts['patuh'],
                'tidak_patuh' => $counts['tidak_patuh'],
                'persentase'  => $fktpTotal > 0
                    ? round(($counts['patuh'] / $fktpTotal) * 100, 1)
                    : 0,
            ];
        }
        // Urutkan: FKTP dengan total terbanyak dulu
        usort($perFktp, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total_pasien'      => $totalPasien,
            'total_diagnosis'   => $semuaPasien->count(),
            'total_aktif'       => $totalAktif,
            'total_pasif'       => $pasienPasif->count(),
            'total_meninggal'   => $semuaPasien->where('status', 'Meninggal')->count(),
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

    /**
     * =========================================================
     * HITUNG TREN KEPATUHAN PER BULAN (GRAFIK DASHBOARD)
     * =========================================================
     *
     * Setiap bulan dinilai dengan tentukanStatusKepatuhanPeriode() — logika
     * yang sama dengan KPI dashboard saat filter periode dipilih — sehingga
     * angka tren bulan X selalu sama dengan KPI periode X.
     *
     * Satu pasien = satu siklus per bulan (bukan satu baris riwayat), dan
     * pasien yang jadwalnya terlewat tanpa riwayat ikut dihitung Tidak Teratur.
     * Bulan tanpa pasien yang dinilai → persentase null (garis putus,
     * bukan jatuh ke 0%).
     *
     * @return array<int, array{periode: string, bulan: string, patuh: int, tidak_patuh: int, persentase: ?float}>
     */
    public function hitungTrenKepatuhan(
        ?string $tanggalEvaluasi = null,
        ?string $filterFktp = null,
        int $jumlahBulan = 12
    ): array {
        $evaluasi = $tanggalEvaluasi ?: self::tanggalEvaluasiBawaan();
        $akhir    = Carbon::parse($evaluasi)->startOfMonth();

        $query = PasienPrb::aktif()->with('riwayatPengambilanObat');
        if ($filterFktp) {
            $query->where('fktp_asal', $filterFktp);
        }
        $pasienAktif = $query->get();

        // Pasien hanya bisa Patuh/Tidak Teratur di bulan yang punya siklus:
        // jadwal_target riwayat (yang ada tanggal ambilnya) atau jadwal aktif
        // master — sama dengan langkah 1 & 2 tentukanStatusKepatuhanPeriode().
        // Bulan lain pasti "Tidak Ada Transaksi", jadi tidak perlu dievaluasi.
        $pasienPerBulan = [];
        foreach ($pasienAktif as $pasien) {
            $bulanSiklus = [];
            foreach ($pasien->riwayatPengambilanObat as $r) {
                $jadwal = self::tanggalIso($r, 'jadwal_target');
                if ($jadwal !== null && self::tanggalIso($r, 'tanggal_pengambilan') !== null) {
                    $bulanSiklus[substr($jadwal, 0, 7)] = true;
                }
            }
            $jadwalAktif = self::tanggalIso($pasien, 'tanggal_jadwal_berikutnya');
            if ($jadwalAktif !== null) {
                $bulanSiklus[substr($jadwalAktif, 0, 7)] = true;
            }
            foreach (array_keys($bulanSiklus) as $b) {
                $pasienPerBulan[$b][] = $pasien;
            }
        }

        // Periode penelitian ditetapkan → tren hanya bulan-bulan itu
        $daftarBulan = self::periodePenelitian()
            ?: array_map(fn ($i) => $akhir->copy()->subMonths($i)->format('Y-m'), range($jumlahBulan - 1, 0));

        $tren = [];
        foreach ($daftarBulan as $periode) {
            $bulan = Carbon::parse($periode . '-01');

            $patuh = 0;
            $tidakPatuh = 0;
            foreach ($pasienPerBulan[$periode] ?? [] as $pasien) {
                $status = $this->tentukanStatusKepatuhanPeriode($pasien, $periode, $evaluasi)['status'];
                if ($status === 'Patuh')       $patuh++;
                if ($status === 'Tidak Teratur') $tidakPatuh++;
            }

            $tren[] = [
                'periode'     => $periode,
                'bulan'       => $bulan->translatedFormat('M Y'),
                'patuh'       => $patuh,
                'tidak_patuh' => $tidakPatuh,
                'persentase'  => self::persentasePatuh($patuh, $tidakPatuh),
            ];
        }

        return $tren;
    }
}
