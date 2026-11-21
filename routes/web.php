<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasienPrbController;
use App\Http\Controllers\TindakLanjutController;
use App\Http\Controllers\PengingatWaController;
use App\Http\Controllers\RekapKepatuhanController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// AUTH (guest only)
// ─────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ─────────────────────────────────────────────
// PROTECTED ROUTES (auth required)
// ─────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Rekap Kepatuhan
    Route::get('/rekap', [RekapKepatuhanController::class, 'index'])->name('rekap.index');
    Route::get('/rekap/export', [RekapKepatuhanController::class, 'export'])->name('rekap.export');

    // Pasien CRUD
    Route::resource('pasien', PasienPrbController::class)->except(['create', 'edit']);

    // Pasien aksi tambahan
    Route::post('pasien/{pasien}/realisasi', [PasienPrbController::class, 'updateRealisasi'])->name('pasien.realisasi');
    Route::put('pasien/{pasien}/realisasi', [PasienPrbController::class, 'updateRealisasiEdit'])->name('pasien.realisasi.edit');
    Route::delete('pasien/{pasien}/realisasi/{riwayat}', [PasienPrbController::class, 'hapusRealisasi'])->name('pasien.realisasi.hapus');
    Route::put('pasien/{pasien}/jadwal', [PasienPrbController::class, 'updateJadwal'])->name('pasien.jadwal.update');
    Route::put('pasien/{pasien}/terakhir-ambil', [PasienPrbController::class, 'updateTerakhirAmbil'])->name('pasien.terakhir-ambil.update');
    Route::post('pasien/{pasien}/status', [PasienPrbController::class, 'updateStatus'])->name('pasien.status');
    Route::post('pasien/import/batch', [PasienPrbController::class, 'import'])->name('pasien.import');
    Route::post('pasien/import/tidak-valid', [PasienPrbController::class, 'importTidakValid'])->name('pasien.import.tidak-valid');
    Route::post('pasien/hapus-massal', [PasienPrbController::class, 'destroyBulk'])->name('pasien.destroy-bulk');
    Route::get('pasien-export', [PasienPrbController::class, 'export'])->name('pasien.export');
    Route::post('pasien-export', [PasienPrbController::class, 'export'])->name('pasien.export.terpilih');

    // Tindak Lanjut
    Route::post('pasien/{pasien}/tindak-lanjut', [TindakLanjutController::class, 'store'])->name('tindak-lanjut.store');
    Route::post('pasien/{pasien}/pengingat-wa', [PengingatWaController::class, 'store'])->name('pengingat-wa.store');

    // Audit Log
    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

    // Akun
    Route::get('/akun/password', [AuthController::class, 'showChangePassword'])->name('password.edit');
    Route::put('/akun/password', [AuthController::class, 'updatePassword'])->name('password.update');

});
