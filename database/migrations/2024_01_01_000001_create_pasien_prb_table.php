<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasien_prb', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kode_pasien')->unique();
            $table->string('nama_pasien');
            $table->string('no_kartu_bpjs');
            $table->string('no_whatsapp')->nullable();
            $table->string('fktp_asal');
            $table->string('diagnosis_prb');
            $table->date('tanggal_mulai_prb');
            $table->date('tanggal_pengambilan_terakhir')->nullable();
            $table->date('tanggal_jadwal_berikutnya')->nullable();
            $table->date('tanggal_realisasi')->nullable();
            $table->integer('jatah_obat')->default(30);
            $table->string('status')->default('Aktif');
            $table->string('alasan_pasif')->nullable();
            $table->string('sumber_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasien_prb');
    }
};
