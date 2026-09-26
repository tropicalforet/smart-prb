<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatPengambilanObat extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'riwayat_pengambilan_obat';

    protected $fillable = [
        'pasien_prb_id',
        'no_faktur',
        'tanggal_berobat',
        'tanggal_pengambilan',
        'jadwal_target',
        'selisih_hari',
        'status_kepatuhan',
    ];

    protected $casts = [
        'tanggal_berobat' => 'date',
        'tanggal_pengambilan' => 'date',
        'jadwal_target' => 'date',
        'selisih_hari' => 'integer',
    ];

    public function pasienPrb(): BelongsTo
    {
        return $this->belongsTo(PasienPrb::class, 'pasien_prb_id');
    }
}
