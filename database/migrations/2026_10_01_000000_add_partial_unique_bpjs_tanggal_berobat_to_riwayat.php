<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambahkan partial unique index pada riwayat_pengambilan_obat
     * untuk kombinasi (pasien_prb_id, tanggal_berobat) WHERE no_faktur IS NULL.
     *
     * Tujuan: mencegah duplikasi transaksi tanpa faktur pada pasien yang sama
     * di tanggal berobat yang sama.
     *
     * Menggunakan raw SQL karena Laravel Schema Builder tidak mendukung
     * partial/filtered index secara native — hanya database yang mendukung
     * syntax WHERE pada CREATE UNIQUE INDEX (SQLite, PostgreSQL).
     *
     * SQLite support: YES (CREATE UNIQUE INDEX ... WHERE ...)
     */
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS
                riwayat_bpjs_tgl_berobat_no_faktur_null_unique
            ON riwayat_pengambilan_obat (pasien_prb_id, tanggal_berobat)
            WHERE no_faktur IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('
            DROP INDEX IF EXISTS riwayat_bpjs_tgl_berobat_no_faktur_null_unique
        ');
    }
};
