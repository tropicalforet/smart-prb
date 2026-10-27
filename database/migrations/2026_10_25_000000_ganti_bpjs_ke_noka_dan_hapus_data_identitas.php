<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian etik penelitian (format data dari apotek):
 *
 *   1. No. Kartu BPJS diganti NOKA / NOKA tersandi (format bebas)
 *      → kolom no_kartu_bpjs di-rename menjadi noka.
 *   2. Nama pasien & No. HP TIDAK BOLEH disimpan
 *      → kolom nama_pasien & no_whatsapp dihapus dari tabel master
 *        pasien dan tabel staging impor, sehingga sistem secara teknis
 *        tidak dapat menyimpan data identitas tersebut.
 *
 * Pasien diidentifikasi dengan kode_pasien + noka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropUnique('pasien_prb_no_kartu_bpjs_unique');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->renameColumn('no_kartu_bpjs', 'noka');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->unique('noka', 'pasien_prb_noka_unique');
            $table->dropColumn(['nama_pasien', 'no_whatsapp']);
        });

        Schema::table('resep_import', function (Blueprint $table) {
            $table->dropIndex('resep_import_no_kartu_bpjs_tanggal_berobat_index');
        });
        Schema::table('resep_import', function (Blueprint $table) {
            $table->renameColumn('no_kartu_bpjs', 'noka');
        });
        Schema::table('resep_import', function (Blueprint $table) {
            $table->index(['noka', 'tanggal_berobat'], 'resep_import_noka_tanggal_berobat_index');
            $table->dropColumn(['nama_pasien', 'no_whatsapp']);
        });
    }

    public function down(): void
    {
        // Kolom identitas dikembalikan sebagai nullable (datanya tidak bisa dipulihkan)
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropUnique('pasien_prb_noka_unique');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->renameColumn('noka', 'no_kartu_bpjs');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->unique('no_kartu_bpjs', 'pasien_prb_no_kartu_bpjs_unique');
            $table->string('nama_pasien')->nullable();
            $table->string('no_whatsapp')->nullable();
        });

        Schema::table('resep_import', function (Blueprint $table) {
            $table->dropIndex('resep_import_noka_tanggal_berobat_index');
        });
        Schema::table('resep_import', function (Blueprint $table) {
            $table->renameColumn('noka', 'no_kartu_bpjs');
        });
        Schema::table('resep_import', function (Blueprint $table) {
            $table->index(['no_kartu_bpjs', 'tanggal_berobat'], 'resep_import_no_kartu_bpjs_tanggal_berobat_index');
            $table->string('nama_pasien')->nullable();
            $table->string('no_whatsapp')->nullable();
        });
    }
};
