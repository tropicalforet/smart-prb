<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    /**
     * Rekaman audit bersifat append-only: tidak dapat diubah atau dihapus
     * melalui sistem (ruang lingkup poin k). Upaya update/delete lewat
     * Eloquent ditolak dengan exception.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Audit log tidak dapat diubah.');
        });
        static::deleting(function () {
            throw new \LogicException('Audit log tidak dapat dihapus.');
        });
    }

    protected $fillable = [
        'petugas',
        'modul',
        'aksi',
        'deskripsi',
        'entitas_id',
        'entitas_label',
        'perubahan',
    ];

    protected $casts = [
        'perubahan'  => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Label modul yang mudah dibaca
    public const MODUL_LABELS = [
        'pasien'          => 'Data Pasien',
        'realisasi'       => 'Realisasi Pengambilan',
        'tindak_lanjut'   => 'Tindak Lanjut',
        'import'          => 'Import Excel',
        'hapus_massal'    => 'Hapus Massal',
        'reminder'        => 'Reminder WhatsApp',
        'auth'            => 'Autentikasi',
    ];

    // Label aksi yang mudah dibaca
    public const AKSI_LABELS = [
        'tambah'             => 'Tambah',
        'ubah'               => 'Ubah',
        'hapus'              => 'Hapus',
        'ubah_status'        => 'Ubah Status',
        'catat_realisasi'    => 'Catat Realisasi',
        'edit_realisasi'     => 'Edit Realisasi',
        'import'             => 'Import',
        'hapus_massal'       => 'Hapus Massal',
        'catat_tindak_lanjut'=> 'Catat Tindak Lanjut',
        'buka_reminder'      => 'Buka Reminder',
        'login'              => 'Login',
        'logout'             => 'Logout',
        'ganti_password'     => 'Ganti Password',
    ];
}
