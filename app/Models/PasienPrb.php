<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PasienPrb extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pasien_prb';

    protected $fillable = [
        'kode_pasien',
        'noka',   // NOKA / NOKA tersandi (pengganti No. Kartu BPJS)
        'nama_samaran', // nama yang sudah disamarkan, mis. "R***a D*i P***i"
        'no_whatsapp', // opsional, hanya untuk pengingat ambil obat
        'fktp_asal',
        'diagnosis_prb',
        'tanggal_mulai_prb',
        'tanggal_pengambilan_terakhir',
        'tanggal_jadwal_berikutnya',
        'tanggal_realisasi',
        'jatah_obat',
        'status',
        'alasan_pasif',
        'sumber_data',
        'jenis_prb',
    ];

    /**
     * Satu NOKA bisa punya beberapa data PRB — satu per diagnosis — masing-
     * masing dengan jadwal & kepatuhan sendiri. kunci_diagnosis (NOKA +
     * kunci ini unik) selalu disinkronkan dari diagnosis_prb saat disimpan.
     */
    protected static function booted(): void
    {
        static::saving(function (PasienPrb $pasien) {
            $pasien->kunci_diagnosis = self::kunciDiagnosis((array) $pasien->diagnosis_prb);
        });
    }

    /** ["Hipertensi", "Asma"] → "asma|hipertensi" (urut, huruf kecil, tanpa ganda). */
    public static function kunciDiagnosis(array $diagnosis): string
    {
        $kunci = array_values(array_unique(array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            $diagnosis
        ))));
        sort($kunci);

        return implode('|', $kunci);
    }

    /** Data PRB milik NOKA ini untuk diagnosis yang sama persis (bila ada). */
    public static function cariPerDiagnosis(string $noka, array $diagnosis): ?self
    {
        return static::where('noka', $noka)
            ->where('kunci_diagnosis', self::kunciDiagnosis($diagnosis))
            ->first();
    }

    protected $casts = [
        'tanggal_mulai_prb' => 'date',
        'tanggal_pengambilan_terakhir' => 'date',
        'tanggal_jadwal_berikutnya' => 'date',
        'tanggal_realisasi' => 'date',
        'jatah_obat' => 'integer',
        'diagnosis_prb' => 'array',
    ];

    /**
     * 9 diagnosis Program Rujuk Balik — satu-satunya diagnosis yang diterima
     * (form, import, filter). Diagnosis lain di luar penelitian.
     */
    public const DIAGNOSIS_OPTIONS = [
        'Diabetes Melitus',
        'Hipertensi',
        'Penyakit Jantung',
        'Asma',
        'PPOK',
        'Stroke',
        'Epilepsi',
        'Skizofrenia',
        'SLE',
    ];

    /** Singkatan di data apotek (sama dengan ALIAS_DIAGNOSIS di excel-utils.ts). */
    private const ALIAS_DIAGNOSIS = [
        'HT' => 'Hipertensi',
        'DM' => 'Diabetes Melitus',
        'JANTUNG' => 'Penyakit Jantung', 'PJK' => 'Penyakit Jantung',
        'SRTOKE' => 'Stroke',
        'EPI' => 'Epilepsi',
        'SKIZO' => 'Skizofrenia', 'SKIZOFREN' => 'Skizofrenia', 'SCZ' => 'Skizofrenia',

        // Pemetaan diagnosis penyerta ke kategori PRB (notulensi meeting
        // 15 November 2026) — dibakukan otomatis saat impor.
        'CHF' => 'Penyakit Jantung', 'GAGAL JANTUNG' => 'Penyakit Jantung',
        'DLP' => 'Penyakit Jantung', 'DISLIPIDEMIA' => 'Penyakit Jantung',
        'ARITMIA' => 'Penyakit Jantung',
        'NEUROPATI' => 'Diabetes Melitus', 'NEUROPATHY' => 'Diabetes Melitus',
        'GOUT' => 'Diabetes Melitus', 'GOUT ARTHRITIS' => 'Diabetes Melitus',
        'HIPERURISEMIA' => 'Diabetes Melitus', 'HYPERURICEMIA' => 'Diabetes Melitus',
        'ASAM URAT' => 'Diabetes Melitus', 'AU' => 'Diabetes Melitus',
        'BPH' => 'Hipertensi',
        'PSIKIATRI' => 'Skizofrenia', 'PARKINSON' => 'Skizofrenia', 'SCHIZOPHRENIA' => 'Skizofrenia',
        'ANEMIA' => 'Asma',
    ];

    /**
     * Jumlah pasien unik (orang) berdasarkan NOKA. Satu pasien dengan beberapa
     * diagnosis punya beberapa data PRB, tetapi dihitung satu kali. Data tanpa
     * NOKA tidak bisa dipastikan sama/berbeda, jadi dihitung per data.
     *
     * @param  iterable<PasienPrb|array|string|null>  $data  model, baris ['noka' => ...], atau NOKA
     */
    public static function jumlahPasienUnik(iterable $data): int
    {
        $unik = [];
        $tanpaNoka = 0;

        foreach ($data as $item) {
            $noka = is_string($item) || $item === null ? $item : data_get($item, 'noka');
            $kunci = strtoupper(preg_replace('/\s+/', '', (string) $noka));
            $kunci === '' ? $tanpaNoka++ : $unik[$kunci] = true;
        }

        return count($unik) + $tanpaNoka;
    }

    /**
     * Nama baku salah satu dari 9 diagnosis PRB ("dm", "HT", "asma" →
     * "Diabetes Melitus", "Hipertensi", "Asma"); null bila di luar 9 diagnosis.
     */
    public static function bakukanDiagnosis(?string $diagnosis): ?string
    {
        $kunci = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $diagnosis)));
        foreach (self::DIAGNOSIS_OPTIONS as $opsi) {
            if (mb_strtoupper($opsi) === $kunci) {
                return $opsi;
            }
        }

        return self::ALIAS_DIAGNOSIS[$kunci] ?? null;
    }

    /** Pasif & Meninggal keluar dari pemantauan (tidak dinilai kepatuhannya). */
    public const STATUS_OPTIONS = ['Aktif', 'Pasif', 'Meninggal'];

    public const JENIS_PRB_OPTIONS = ['Baru', 'Lama'];

    public const ALASAN_PASIF_OPTIONS = [
        'Rujuk Balik ke RS',
        'Pindah Faskes',
        'Menolak Program',
        'Pasien Mandiri',
        'Kondisi Stabil',
    ];

    public function riwayatPengambilanObat(): HasMany
    {
        return $this->hasMany(RiwayatPengambilanObat::class, 'pasien_prb_id');
    }

    public function riwayatTindakLanjut(): HasMany
    {
        return $this->hasMany(RiwayatTindakLanjut::class, 'pasien_prb_id');
    }

    /** Histori pengiriman pengingat WhatsApp. */
    public function riwayatPengingatWa(): HasMany
    {
        return $this->hasMany(RiwayatPengingatWa::class, 'pasien_prb_id');
    }

    public function scopeAktif($query)
    {
        return $query->where('status', 'Aktif');
    }

    public function scopePasif($query)
    {
        return $query->where('status', 'Pasif');
    }

    /**
     * Label pasien untuk audit log & pesan UI.
     * Sesuai ketentuan etik, nama pasien tidak disimpan — pasien
     * diidentifikasi dengan kode pasien + diagnosis + NOKA. Diagnosis wajib
     * ikut karena satu kode (ID_PRB) bisa punya beberapa data PRB.
     */
    public function identitas(): string
    {
        $diagnosis = implode(', ', array_filter((array) $this->diagnosis_prb));

        return $this->kode_pasien
            . ($diagnosis !== '' ? " · {$diagnosis}" : '')
            . " (NOKA {$this->noka})";
    }

    /**
     * Samarkan nama: tiap kata hanya huruf pertama & terakhir yang terlihat.
     * "Rizka Dwi Putri" → "R***a D*i P***i". Kata 2 huruf → "A*".
     * Aman diulang (nama yang sudah disamarkan tidak berubah). Kosong → null.
     */
    public static function samarkanNama(?string $nama): ?string
    {
        $nama = trim((string) preg_replace('/\s+/u', ' ', (string) $nama));

        if ($nama === '') {
            return null;
        }

        $kata = array_map(function (string $k): string {
            $huruf = mb_str_split($k);
            $n     = count($huruf);

            return match (true) {
                $n <= 1  => $k,
                $n === 2 => $huruf[0] . '*',
                default  => $huruf[0] . str_repeat('*', $n - 2) . $huruf[$n - 1],
            };
        }, explode(' ', $nama));

        return mb_substr(implode(' ', $kata), 0, 150);
    }

    /**
     * Rapikan No. WhatsApp ke format internasional tanpa tanda:
     * "0812-3456-7890" / "+62 812 3456 7890" / "812..." → "6281234567890".
     * Kosong → null.
     */
    public static function normalisasiNoWhatsapp(?string $nomor): ?string
    {
        $angka = preg_replace('/\D/', '', (string) $nomor);

        if ($angka === '') {
            return null;
        }

        if (str_starts_with($angka, '0')) {
            return '62' . substr($angka, 1);
        }

        if (str_starts_with($angka, '8')) {
            return '62' . $angka;
        }

        return $angka;
    }

    /**
     * Generate kode pasien berikutnya secara otomatis.
     */
    public static function generateKodePasien(): string
    {
        $last = static::orderByRaw("CAST(REPLACE(kode_pasien, 'PRB-', '') AS INTEGER) DESC")->first();
        $nextNumber = $last ? (int) str_replace('PRB-', '', $last->kode_pasien) + 1 : 1;
        return 'PRB-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
