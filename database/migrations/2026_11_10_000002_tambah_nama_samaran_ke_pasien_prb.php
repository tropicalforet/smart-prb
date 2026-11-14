<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama pasien yang DISAMARKAN (mis. "R***a D*i P***i").
 *
 * Nama asli tidak pernah disimpan: penyamaran dilakukan di browser sebelum
 * data dikirim, dan diulang di server sebagai pengaman (lihat
 * PasienPrb::samarkanNama()). Kolom nama_pasien asli tetap tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->string('nama_samaran', 150)->nullable()->after('noka');
        });
    }

    public function down(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropColumn('nama_samaran');
        });
    }
};
