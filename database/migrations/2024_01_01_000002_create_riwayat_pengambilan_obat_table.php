<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_pengambilan_obat', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pasien_prb_id');
            $table->date('tanggal_pengambilan');
            $table->date('jadwal_target');
            $table->integer('selisih_hari')->default(0);
            $table->string('status_kepatuhan')->default('Patuh');
            $table->timestamps();

            $table->foreign('pasien_prb_id')
                  ->references('id')
                  ->on('pasien_prb')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_pengambilan_obat');
    }
};
