<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode pasien mengikuti ID_PRB apotek: satu pasien dengan dua diagnosis
 * punya dua data PRB dengan kode yang sama (dibedakan diagnosis).
 * Yang unik tetap NOKA + diagnosis (pasien_prb_noka_diagnosis_unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropUnique('pasien_prb_kode_pasien_unique');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->index('kode_pasien', 'pasien_prb_kode_pasien_index');
        });
    }

    /**
     * Gagal bila sudah ada kode yang sama untuk beberapa diagnosis.
     */
    public function down(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropIndex('pasien_prb_kode_pasien_index');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->unique('kode_pasien', 'pasien_prb_kode_pasien_unique');
        });
    }
};
