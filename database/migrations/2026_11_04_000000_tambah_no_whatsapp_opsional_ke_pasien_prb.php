<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. WhatsApp OPSIONAL untuk pengingat ambil obat (Early Warning ke pasien).
 *
 * Hanya diisi manual lewat form pasien — tidak pernah dari import Excel —
 * dan hanya untuk pasien yang bersedia dihubungi. Pasien tanpa nomor tetap
 * dipantau seperti biasa. Disimpan dalam format 628xxxxxxxxxx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->string('no_whatsapp', 20)->nullable()->after('noka');
        });
    }

    public function down(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropColumn('no_whatsapp');
        });
    }
};
