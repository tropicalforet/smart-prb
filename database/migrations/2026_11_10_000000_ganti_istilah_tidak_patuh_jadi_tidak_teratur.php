<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Istilah status "Tidak Patuh" diganti "Tidak Teratur" (permintaan pengguna).
 * Status per pengambilan yang sudah tersimpan ikut diperbarui agar istilahnya
 * seragam di halaman, rekap, dan export. Catatan audit log lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('riwayat_pengambilan_obat')
            ->where('status_kepatuhan', 'Tidak Patuh')
            ->update(['status_kepatuhan' => 'Tidak Teratur']);
    }

    public function down(): void
    {
        DB::table('riwayat_pengambilan_obat')
            ->where('status_kepatuhan', 'Tidak Teratur')
            ->update(['status_kepatuhan' => 'Tidak Patuh']);
    }
};
