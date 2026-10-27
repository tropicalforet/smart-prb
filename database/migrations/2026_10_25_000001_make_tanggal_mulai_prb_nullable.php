<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data apotek tidak selalu mencantumkan TGL MULAI PRB (±25% pasien pada
 * data penelitian). Pasien tetap diimpor; tanpa tanggal mulai pasien
 * dianggap PRB Lama dan jadwal dihitung dari pengambilan terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->date('tanggal_mulai_prb')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Isi kekosongan dengan tanggal pengambilan terakhir / tanggal dibuat
        // agar constraint NOT NULL bisa dipasang kembali.
        DB::table('pasien_prb')
            ->whereNull('tanggal_mulai_prb')
            ->update(['tanggal_mulai_prb' => DB::raw('COALESCE(tanggal_pengambilan_terakhir, date(created_at))')]);

        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->date('tanggal_mulai_prb')->nullable(false)->change();
        });
    }
};
