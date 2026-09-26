<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resep_import', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('no_kartu_bpjs', 13);
            $table->string('nama_pasien');
            $table->string('fktp_asal');
            $table->json('diagnosis_prb')->nullable();

            // Tanggal pasien berobat / transaksi pelayanan
            $table->date('tanggal_berobat');

            // Nomor transaksi dari sistem apotek
            $table->string('no_faktur')->nullable();

            // Tanggal pasien mengambil obat
            $table->date('tanggal_pengambilan')->nullable();

            $table->date('tanggal_mulai_prb')->nullable();

            // Hasil pemeriksaan sebelum masuk tabel utama
            $table->string('status_import')->default('pending');
            // pending | baru | duplikat | invalid

            $table->text('keterangan_import')->nullable();

            $table->timestamps();

            // Membantu pencarian duplikasi
            $table->index('no_faktur');
            $table->index([
                'no_kartu_bpjs',
                'tanggal_berobat',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resep_import');
    }
};