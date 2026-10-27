<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PasienPrb;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    /**
     * Field master pasien yang dicatat pada rincian perubahan,
     * beserta label yang ditampilkan di halaman Audit Log.
     */
    public const FIELD_PASIEN = [
        'noka'                         => 'NOKA',
        'fktp_asal'                    => 'FKTP',
        'diagnosis_prb'                => 'Diagnosis',
        'tanggal_mulai_prb'            => 'Tanggal Mulai PRB',
        'tanggal_pengambilan_terakhir' => 'Terakhir Ambil Obat',
        'tanggal_jadwal_berikutnya'    => 'Jadwal Berikutnya',
        'jenis_prb'                    => 'Jenis PRB',
        'jatah_obat'                   => 'Jatah Obat (hari)',
        'status'                       => 'Status',
        'alasan_pasif'                 => 'Alasan Pasif',
    ];

    /**
     * Catat satu entri audit log.
     *
     * @param string      $modul        Kategori modul (pasien|realisasi|tindak_lanjut|import|hapus_massal)
     * @param string      $aksi         Aksi spesifik (tambah|ubah|hapus|dll)
     * @param string      $deskripsi    Kalimat singkat yang bisa dibaca manusia
     * @param string|null $entitasId    ID entitas yang terdampak (opsional)
     * @param string|null $entitasLabel Label entitas untuk tampilan (opsional)
     * @param array       $perubahan    Rincian perubahan: [['kolom' => .., 'lama' => .., 'baru' => ..], ...]
     */
    public function catat(
        string $modul,
        string $aksi,
        string $deskripsi,
        ?string $entitasId    = null,
        ?string $entitasLabel = null,
        array $perubahan      = []
    ): void {
        // Ambil nama petugas dari auth user, fallback ke 'Admin'
        $petugas = Auth::check() ? Auth::user()->name : 'Admin';

        // Fire-and-forget — jangan sampai kegagalan audit log mengganggu operasi utama
        try {
            AuditLog::create([
                'petugas'       => $petugas,
                'modul'         => $modul,
                'aksi'          => $aksi,
                'deskripsi'     => $deskripsi,
                'entitas_id'    => $entitasId,
                'entitas_label' => $entitasLabel,
                'perubahan'     => $perubahan ?: null,
            ]);
        } catch (\Throwable $e) {
            // Log ke Laravel log tapi tidak throw exception
            \Illuminate\Support\Facades\Log::warning(
                '[AuditLog] Gagal mencatat audit log: ' . $e->getMessage(),
                ['modul' => $modul, 'aksi' => $aksi, 'deskripsi' => $deskripsi]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // RINCIAN PERUBAHAN
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Nilai field master pasien dalam bentuk teks yang mudah dibaca
     * (tanggal → DD/MM/YYYY, array → dipisah koma). Diambil sebelum
     * dan sesudah perubahan, lalu dibandingkan dengan selisih().
     */
    public static function snapshotPasien(?PasienPrb $pasien): array
    {
        if (!$pasien) {
            return [];
        }

        $hasil = [];
        foreach (array_keys(self::FIELD_PASIEN) as $field) {
            $hasil[$field] = self::teks($pasien->{$field});
        }
        return $hasil;
    }

    /**
     * Bandingkan dua snapshot → daftar field yang berubah.
     * Snapshot kosong di satu sisi berarti data baru dibuat / dihapus.
     *
     * @param array<string,string|null> $lama
     * @param array<string,string|null> $baru
     * @param array<string,string>      $label  field → label tampilan
     */
    public static function selisih(array $lama, array $baru, array $label = self::FIELD_PASIEN): array
    {
        $perubahan = [];
        foreach ($label as $field => $nama) {
            $a = $lama[$field] ?? null;
            $b = $baru[$field] ?? null;
            if ($a !== $b) {
                $perubahan[] = ['kolom' => $nama, 'lama' => $a, 'baru' => $b];
            }
        }
        return $perubahan;
    }

    /**
     * Rincian realisasi pengambilan obat: tanggal ambil, hasil penilaian
     * transaksi (status & selisih terhadap jadwal), lalu perubahan master
     * pasien (terakhir ambil, jadwal berikutnya, jenis PRB, dst).
     *
     * @param array            $sebelum       snapshotPasien() sebelum realisasi
     * @param string           $tanggal       tanggal realisasi (Y-m-d)
     * @param array|null       $riwayatLama   ['tanggal' => .., 'status' => ..] bila mengedit realisasi
     */
    public static function rincianRealisasi(PasienPrb $pasien, array $sebelum, string $tanggal, ?array $riwayatLama = null): array
    {
        $riwayat = $pasien->riwayatPengambilanObat()
            ->where('tanggal_pengambilan', 'like', substr($tanggal, 0, 10) . '%')
            ->first();

        $perubahan = [
            ['kolom' => 'Tanggal Pengambilan', 'lama' => self::teks($riwayatLama['tanggal'] ?? null), 'baru' => self::teks($tanggal)],
        ];
        if ($riwayat) {
            $perubahan[] = ['kolom' => 'Jadwal Target', 'lama' => null, 'baru' => self::teks($riwayat->jadwal_target)];
            $perubahan[] = ['kolom' => 'Status Kepatuhan', 'lama' => $riwayatLama['status'] ?? null, 'baru' => $riwayat->status_kepatuhan];
            $perubahan[] = ['kolom' => 'Selisih Hari', 'lama' => null, 'baru' => (string) $riwayat->selisih_hari];
        }

        return array_merge($perubahan, self::selisih($sebelum, self::snapshotPasien($pasien->fresh())));
    }

    private static function teks(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '' || $nilai === []) {
            return null;
        }
        if ($nilai instanceof CarbonInterface) {
            return $nilai->format('d/m/Y');
        }
        if (is_array($nilai)) {
            return implode(', ', $nilai);
        }
        if (is_string($nilai) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $nilai, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return (string) $nilai;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SHORTHAND METHODS — satu baris per aksi umum
    // ─────────────────────────────────────────────────────────────────────

    public function tambahPasien(string $id, string $label, array $perubahan = []): void
    {
        $this->catat('pasien', 'tambah',
            "Menambah pasien {$label}",
            $id, $label, $perubahan
        );
    }

    public function ubahPasien(string $id, string $label, array $perubahan = []): void
    {
        $this->catat('pasien', 'ubah',
            "Mengubah data pasien {$label}",
            $id, $label, $perubahan
        );
    }

    public function ubahJadwal(string $id, string $label, array $perubahan = []): void
    {
        $this->catat('pasien', 'ubah',
            "Mengubah jadwal pengambilan obat pasien {$label}",
            $id, $label, $perubahan
        );
    }

    public function hapusPasien(string $id, string $label, array $perubahan = []): void
    {
        $this->catat('pasien', 'hapus',
            "Menghapus pasien {$label}",
            $id, $label, $perubahan
        );
    }

    public function ubahStatusPasien(string $id, string $label, string $statusBaru, array $perubahan = []): void
    {
        $this->catat('pasien', 'ubah_status',
            "Mengubah status pasien {$label} menjadi {$statusBaru}",
            $id, $label, $perubahan
        );
    }

    /** @param string[] $identitasTerhapus daftar identitas pasien yang dihapus */
    public function hapusMassal(int $jumlah, array $identitasTerhapus = []): void
    {
        $this->catat('hapus_massal', 'hapus_massal',
            "Menghapus {$jumlah} pasien sekaligus",
            null, null,
            array_map(fn ($idn) => ['kolom' => 'Pasien', 'lama' => $idn, 'baru' => null], $identitasTerhapus)
        );
    }

    public function catatRealisasi(string $id, string $label, string $tanggal, array $perubahan = []): void
    {
        $this->catat('realisasi', 'catat_realisasi',
            "Mencatat realisasi pengambilan obat {$label} pada {$tanggal}",
            $id, $label, $perubahan
        );
    }

    public function editRealisasi(string $id, string $label, string $tanggalBaru, array $perubahan = []): void
    {
        $this->catat('realisasi', 'edit_realisasi',
            "Mengedit realisasi pengambilan obat {$label} menjadi {$tanggalBaru}",
            $id, $label, $perubahan
        );
    }

    public function importExcel(int $baru, int $diperbarui, int $duplikat, int $invalid): void
    {
        $this->catat('import', 'import',
            "Import Excel: {$baru} baru, {$diperbarui} diperbarui, {$duplikat} duplikat, {$invalid} invalid"
        );
    }

    public function catatTindakLanjut(string $id, string $label, string $tindakan, array $perubahan = []): void
    {
        $this->catat('tindak_lanjut', 'catat_tindak_lanjut',
            "Mencatat tindak lanjut '{$tindakan}' untuk pasien {$label}",
            $id, $label, $perubahan
        );
    }

    public function login(): void
    {
        $this->catat('auth', 'login', 'Login ke sistem');
    }

    public function logout(): void
    {
        $this->catat('auth', 'logout', 'Logout dari sistem');
    }

    public function gantiPassword(): void
    {
        $this->catat('auth', 'ganti_password', 'Mengganti password akun');
    }
}
