<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histori pengiriman pengingat WhatsApp: dicatat saat petugas menekan
 * "Kirim Pengingat WA". Nomor WhatsApp dan isi pesan tidak disimpan ulang
 * (etik) — cukup kapan, oleh siapa, tahap pengingat, dan jadwal yang diingatkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_pengingat_wa', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pasien_prb_id');
            $table->dateTime('dikirim_pada');
            $table->string('petugas');
            $table->string('tahap')->nullable();       // mis. "Jadwal mendekat", "Terlambat"
            $table->date('jadwal_target')->nullable(); // jadwal ambil obat yang diingatkan
            $table->timestamps();

            $table->foreign('pasien_prb_id')
                  ->references('id')
                  ->on('pasien_prb')
                  ->onDelete('cascade');
            $table->index(['pasien_prb_id', 'dikirim_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_pengingat_wa');
    }
};
