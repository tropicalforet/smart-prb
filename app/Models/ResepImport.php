<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResepImport extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'resep_import';

    protected $fillable = [
        'kode_pasien',          // ditambahkan: ada di schema sejak 2026-09-28 tapi tidak di fillable
        'no_kartu_bpjs',
        'nama_pasien',
        'no_whatsapp',
        'fktp_asal',
        'diagnosis_prb',
        'tanggal_berobat',
        'no_faktur',
        'tanggal_pengambilan',
        'tanggal_mulai_prb',
        'status_import',
        'keterangan_import',
    ];

    protected $casts = [
        'diagnosis_prb'      => 'array',
        'tanggal_berobat'    => 'date',
        'tanggal_pengambilan'=> 'date',
        'tanggal_mulai_prb'  => 'date',
    ];
}