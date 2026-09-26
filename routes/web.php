<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasienPrbController;
use App\Http\Controllers\TindakLanjutController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Pasien CRUD
Route::resource('pasien', PasienPrbController::class)->except(['create', 'edit']);

// Pasien aksi tambahan
Route::post('pasien/{pasien}/realisasi', [PasienPrbController::class, 'updateRealisasi'])->name('pasien.realisasi');
Route::post('pasien/{pasien}/status', [PasienPrbController::class, 'updateStatus'])->name('pasien.status');
Route::post('pasien/import/batch', [PasienPrbController::class, 'import'])->name('pasien.import');
Route::post('pasien/hapus-massal', [PasienPrbController::class, 'destroyBulk'])->name('pasien.destroy-bulk');
Route::get('pasien-export', [PasienPrbController::class, 'export'])->name('pasien.export');

// Tindak Lanjut
Route::post('pasien/{pasien}/tindak-lanjut', [TindakLanjutController::class, 'store'])->name('tindak-lanjut.store');
