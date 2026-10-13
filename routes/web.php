<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasienPrbController;
use App\Http\Controllers\TindakLanjutController;
use App\Http\Controllers\RekapKepatuhanController;
use App\Http\Controllers\ReminderController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Rekap Kepatuhan
Route::get('/rekap', [RekapKepatuhanController::class, 'index'])->name('rekap.index');

// Reminder WhatsApp
Route::get('/reminder', [ReminderController::class, 'index'])->name('reminder.index');
Route::post('/reminder/blast', [ReminderController::class, 'blast'])->name('reminder.blast');

// Pasien CRUD
Route::resource('pasien', PasienPrbController::class)->except(['create', 'edit']);

// Pasien aksi tambahan
Route::post('pasien/{pasien}/realisasi', [PasienPrbController::class, 'updateRealisasi'])->name('pasien.realisasi');
Route::put('pasien/{pasien}/realisasi', [PasienPrbController::class, 'updateRealisasiEdit'])->name('pasien.realisasi.edit');
Route::put('pasien/{pasien}/jadwal', [PasienPrbController::class, 'updateJadwal'])->name('pasien.jadwal.update');
Route::put('pasien/{pasien}/terakhir-ambil', [PasienPrbController::class, 'updateTerakhirAmbil'])->name('pasien.terakhir-ambil.update');
Route::post('pasien/{pasien}/status', [PasienPrbController::class, 'updateStatus'])->name('pasien.status');
Route::post('pasien/import/batch', [PasienPrbController::class, 'import'])->name('pasien.import');
Route::post('pasien/hapus-massal', [PasienPrbController::class, 'destroyBulk'])->name('pasien.destroy-bulk');
Route::get('pasien-export', [PasienPrbController::class, 'export'])->name('pasien.export');

// Tindak Lanjut
Route::post('pasien/{pasien}/tindak-lanjut', [TindakLanjutController::class, 'store'])->name('tindak-lanjut.store');
