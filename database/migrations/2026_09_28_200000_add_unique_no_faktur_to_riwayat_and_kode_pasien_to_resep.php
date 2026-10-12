<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            // Drop existing non-unique index on no_faktur
            $table->dropIndex(['no_faktur']);
            // Add unique constraint — prevents duplicate transactions
            $table->unique('no_faktur', 'riwayat_no_faktur_unique');
        });

        // Add kode_pasien to resep_import for better tracking
        Schema::table('resep_import', function (Blueprint $table) {
            if (!Schema::hasColumn('resep_import', 'kode_pasien')) {
                $table->string('kode_pasien')->nullable()->after('no_kartu_bpjs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            $table->dropUnique('riwayat_no_faktur_unique');
            $table->index('no_faktur');
        });

        Schema::table('resep_import', function (Blueprint $table) {
            if (Schema::hasColumn('resep_import', 'kode_pasien')) {
                $table->dropColumn('kode_pasien');
            }
        });
    }
};
