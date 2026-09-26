<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            $table->string('no_faktur')->nullable()->after('pasien_prb_id');
            $table->date('tanggal_berobat')->nullable()->after('no_faktur');
            $table->index('no_faktur');
        });
    }

    public function down(): void
    {
        Schema::table('riwayat_pengambilan_obat', function (Blueprint $table) {
            $table->dropIndex(['no_faktur']);
            $table->dropColumn(['no_faktur', 'tanggal_berobat']);
        });
    }
};
