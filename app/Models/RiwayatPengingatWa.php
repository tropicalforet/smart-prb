<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan bahwa pengingat WhatsApp untuk pasien telah dikirim. */
class RiwayatPengingatWa extends Model
{
    use HasUuids;

    protected $table = 'riwayat_pengingat_wa';

    protected $fillable = [
        'pasien_prb_id',
        'dikirim_pada',
        'petugas',
        'tahap',
        'jadwal_target',
    ];

    protected $casts = [
        'dikirim_pada'  => 'datetime',
        'jadwal_target' => 'date',
    ];

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(PasienPrb::class, 'pasien_prb_id');
    }
}
