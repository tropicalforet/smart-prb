<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resep_import', function (Blueprint $table) {
            $table->string('no_whatsapp')->nullable()->after('nama_pasien');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resep_import', function (Blueprint $table) {
            $table->dropColumn('no_whatsapp');
        });
    }
};
