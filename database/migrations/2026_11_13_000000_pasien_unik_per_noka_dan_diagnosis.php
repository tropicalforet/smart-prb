<?php

use App\Models\PasienPrb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu pasien (NOKA) bisa punya beberapa data PRB, satu per diagnosis —
 * masing-masing dengan Tanggal Aktif, pengambilan, jadwal, dan status
 * kepatuhan sendiri (data apotek: satu baris per diagnosis).
 *
 * NOKA tidak lagi unik sendirian; yang unik adalah NOKA + kunci_diagnosis
 * (diagnosis terurut, huruf kecil, dipisah "|"), diisi otomatis oleh model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->string('kunci_diagnosis')->nullable()->after('diagnosis_prb');
        });

        foreach (DB::table('pasien_prb')->select('id', 'diagnosis_prb')->get() as $p) {
            DB::table('pasien_prb')->where('id', $p->id)->update([
                'kunci_diagnosis' => PasienPrb::kunciDiagnosis(json_decode((string) $p->diagnosis_prb, true) ?: []),
            ]);
        }

        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropUnique('pasien_prb_noka_unique');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->unique(['noka', 'kunci_diagnosis'], 'pasien_prb_noka_diagnosis_unique');
            $table->index('noka', 'pasien_prb_noka_index');
        });
    }

    public function down(): void
    {
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->dropUnique('pasien_prb_noka_diagnosis_unique');
            $table->dropIndex('pasien_prb_noka_index');
        });
        Schema::table('pasien_prb', function (Blueprint $table) {
            $table->unique('noka', 'pasien_prb_noka_unique');
            $table->dropColumn('kunci_diagnosis');
        });
    }
};
