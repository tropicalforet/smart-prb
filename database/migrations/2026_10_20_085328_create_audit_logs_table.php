<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Siapa yang melakukan aksi
            $table->string('petugas')->default('Admin');

            // Kategori aksi: pasien | realisasi | tindak_lanjut | import | hapus_massal
            $table->string('modul', 50);

            // Aksi spesifik: tambah | ubah | hapus | catat_realisasi | edit_realisasi |
            //                import | hapus_massal | ubah_status | catat_tindak_lanjut
            $table->string('aksi', 50);

            // Deskripsi singkat yang bisa dibaca manusia
            // Contoh: "Menambah pasien PRB-001 (Budi Santoso)"
            $table->string('deskripsi');

            // ID entitas yang terdampak (pasien_prb.id, dll) — opsional
            $table->string('entitas_id')->nullable();

            // Nama entitas untuk tampilan (nama pasien, kode pasien, dll)
            $table->string('entitas_label')->nullable();

            $table->timestamps();

            // Index untuk filter dan sorting
            $table->index('modul');
            $table->index('aksi');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
