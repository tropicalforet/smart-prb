<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian perubahan pada audit log (ruang lingkup poin k):
 * daftar [{kolom, lama, baru}] untuk setiap field yang berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('perubahan')->nullable()->after('entitas_label');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('perubahan');
        });
    }
};
