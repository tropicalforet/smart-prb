<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatTindakLanjut extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'riwayat_tindak_lanjut';

    protected $fillable = [
        'pasien_prb_id',
        'tanggal_jam',
        'petugas',
        'tindakan',
        'catatan',
        'update_realisasi_otomatis',
    ];

    protected $casts = [
        'tanggal_jam' => 'datetime',
        'update_realisasi_otomatis' => 'boolean',
    ];

    public const TINDAKAN_OPTIONS = [
        'Sudah mengambil obat',
        'Pasien akan datang',
        'Belum dapat dihubungi',
        'Pasien berhalangan',
        'Rujuk FKTP',
        'Lainnya',
    ];

    public function pasienPrb(): BelongsTo
    {
        return $this->belongsTo(PasienPrb::class, 'pasien_prb_id');
    }
}
