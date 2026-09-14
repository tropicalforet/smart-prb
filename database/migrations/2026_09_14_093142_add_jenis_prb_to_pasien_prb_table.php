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
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->string('jenis_prb')->default('Lama')->after('sumber_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropColumn('jenis_prb');
        });
    }
};
