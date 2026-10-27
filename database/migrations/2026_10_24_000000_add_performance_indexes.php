<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk query yang dijalankan di setiap halaman utama.
 *
 * Sebelumnya riwayat_pengambilan_obat hanya punya index PARSIAL pada
 * (pasien_prb_id, tanggal_berobat) WHERE no_faktur IS NULL — SQLite tidak
 * bisa memakainya untuk lookup per pasien biasa, sehingga setiap
 * whereHas('riwayatPengambilanObat') / eager load men-scan seluruh tabel.
 *
 * Migrasi ini hanya MENAMBAH index; tidak mengubah data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            // Eager load per pasien + filter periode (whereHas jadwal_target LIKE 'YYYY-MM%')
            $table->index(['pasien_prb_id', 'jadwal_target'], 'riwayat_pasien_jadwal_index');
            // Daftar periode (DISTINCT) & tren dashboard per bulan
            $table->index('jadwal_target', 'riwayat_jadwal_target_index');
        });

        Schema::table('riwayat_tindak_lanjut', function (Blueprint $table) {
            $table->index(['pasien_prb_id', 'tanggal_jam'], 'tindak_lanjut_pasien_tanggal_index');
        });

        Schema::table('pasien_prb', function (Blueprint $table) {
            // Filter pasien Aktif + urut/filter jadwal berikutnya (dashboard, reminder, periode)
            $table->index(['status', 'tanggal_jadwal_berikutnya'], 'pasien_status_jadwal_index');
        });
    }

    public function down(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            $table->dropIndex('riwayat_pasien_jadwal_index');
            $table->dropIndex('riwayat_jadwal_target_index');
        });

        Schema::table('riwayat_tindak_lanjut', function (Blueprint $table) {
            $table->dropIndex('tindak_lanjut_pasien_tanggal_index');
        });

        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropIndex('pasien_status_jadwal_index');
        });
    }
};
