<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_tindak_lanjut', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pasien_prb_id');
            $table->dateTime('tanggal_jam');
            $table->string('petugas');
            $table->string('tindakan');
            $table->text('catatan')->nullable();
            $table->boolean('update_realisasi_otomatis')->default(false);
            $table->timestamps();

            $table->foreign('pasien_prb_id')
                  ->references('id')
                  ->on('pasien_prb')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_tindak_lanjut');
    }
};
