<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori tindak lanjut (Kontak langsung, Koordinasi FKTP, Meninggal,
 * Kembali ke FKRTL, Membeli obat sendiri, Lain-lain) — melengkapi Hasil
 * Tindakan (Sudah/Belum mengambil obat). Catatan lama tanpa kategori = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_tindak_lanjut', function (Blueprint $table) {
            $table->string('kategori')->nullable()->after('tindakan');
        });
    }

    public function down(): void
    {
        Schema::table('riwayat_tindak_lanjut', function (Blueprint $table) {
            $table->dropColumn('kategori');
        });
    }
};
