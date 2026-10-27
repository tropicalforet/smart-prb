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

    protected $casts = [
        'tanggal_mulai_prb' => 'date',
        'tanggal_pengambilan_terakhir' => 'date',
        'tanggal_jadwal_berikutnya' => 'date',
        'tanggal_realisasi' => 'date',
        'jatah_obat' => 'integer',
        'diagnosis_prb' => 'array',
    ];

    /**
     * Daftar referensi diagnosis PRB yang umum ditangani apotek BPJS.
     * Digunakan sebagai pilihan dropdown di form manual (UI).
     *
     * BUKAN VALIDATOR: Diagnosis dari import Excel atau input bebas
     * tidak dibatasi oleh daftar ini. Apotek/FKTP boleh memasukkan
     * diagnosis apapun yang sesuai data aktual mereka.
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
        'Lainnya',
    ];

    public const STATUS_OPTIONS = ['Aktif', 'Pasif'];

    public const JENIS_PRB_OPTIONS = ['Baru', 'Lama'];

    public const ALASAN_PASIF_OPTIONS = [
        'Rujuk Balik ke RS',
        'Meninggal Dunia',
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
     * diidentifikasi dengan kode pasien + NOKA.
     */
    public function identitas(): string
    {
        return "{$this->kode_pasien} (NOKA {$this->noka})";
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
