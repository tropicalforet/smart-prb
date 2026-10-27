<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perbaikan tabel resep_import untuk mendukung format template baru:
     *
     * 1. tanggal_berobat → nullable
     *    Template baru (berbasis NOKA) tidak memiliki kolom Tanggal Berobat.
     *    Field ini hanya relevan untuk import format lama (dengan no. faktur).
     *
     * 2. no_kartu_bpjs → string tanpa batas panjang tetap
     *    Template baru menggunakan NOKA (format bebas, mis. "PRB KPMA 001")
     *    bukan No. Kartu BPJS 13 digit. Panjang kolom diperlebar ke 100.
     */
    public function up(): void
    {
        Schema::table('resep_import', function (Blueprint $table) {
            // Ubah tanggal_berobat menjadi nullable
            $table->date('tanggal_berobat')->nullable()->change();

            // Perlebar no_kartu_bpjs agar muat NOKA format bebas
            $table->string('no_kartu_bpjs', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resep_import', function (Blueprint $table) {
            $table->date('tanggal_berobat')->nullable(false)->change();
            $table->string('no_kartu_bpjs', 13)->change();
        });
    }
};
