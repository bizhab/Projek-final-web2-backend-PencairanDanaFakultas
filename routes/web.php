<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\SapdfController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Api\FileAccessController;

// Root → Login
Route::get('/', function () {
    return redirect()->route('login');
});

// Dashboard
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Profile (bawaan Laravel Breeze)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ============================================================
// SAPDF Routes — Semua Memerlukan Auth
// ============================================================
Route::middleware('auth')->group(function () {

    // === AKSES FILE (PDF) ===
    Route::get('/files/{fundRequest}/signed-sk/download', [FileAccessController::class, 'downloadSignedSk'])->name('files.download-signed-sk');
    Route::get('/files/{fundRequest}/signed-lpj/download', [FileAccessController::class, 'downloadSignedLpj'])->name('files.download-signed-lpj');
    Route::get('/files/{fundRequest}/{fileType}', [FileAccessController::class, 'previewFile'])->name('files.preview');

    // === ORGANISASI: Pengajuan SK & RAB ===
    Route::get('/pengajuan', [SapdfController::class, 'pengajuanIndex'])->name('pengajuan.index');
    Route::post('/pengajuan', [SapdfController::class, 'pengajuanStore'])->name('pengajuan.store');
    Route::post('/pengajuan/{fundRequest}/revisi', [SapdfController::class, 'pengajuanRevisi'])->name('pengajuan.revisi');

    // === KEUANGAN LT.2: Verifikasi Berkas ===
    Route::get('/verifikasi', [SapdfController::class, 'verifikasiIndex'])->name('verifikasi.index');
    Route::post('/verifikasi/{fundRequest}/review', [SapdfController::class, 'verifikasiReview'])->name('verifikasi.review');

    // === KEUANGAN LT.2: Pencairan Dana ===
    Route::get('/pencairan', [SapdfController::class, 'pencairanIndex'])->name('pencairan.index');
    Route::post('/pencairan/{fundRequest}/confirm', [SapdfController::class, 'pencairanConfirm'])->name('pencairan.confirm');

    // === DEKANAT/ADMIN: ACC Pengajuan ===
    Route::get('/dekanat', [SapdfController::class, 'dekanatIndex'])->name('dekanat.index');
    Route::post('/dekanat/{fundRequest}/review', [SapdfController::class, 'dekanatReview'])->name('dekanat.review');

    // === DEKANAT/ADMIN: Kelola Organisasi ===
    Route::get('/organisasi', [SapdfController::class, 'organisasiIndex'])->name('organisasi.index');
    Route::post('/organisasi/{user}/reset-password', [SapdfController::class, 'organisasiResetPassword'])->name('organisasi.reset-password');

    // === LPJ: Semua Role (konteks berbeda berdasar role) ===
    Route::get('/lpj', [SapdfController::class, 'lpjIndex'])->name('lpj.index');
    Route::post('/lpj/{fundRequest}/submit', [SapdfController::class, 'lpjStore'])->name('lpj.store');
    Route::post('/lpj/{fundRequest}/revisi', [SapdfController::class, 'lpjRevisi'])->name('lpj.revisi');
    Route::post('/lpj/{fundRequest}/review', [SapdfController::class, 'lpjReview'])->name('lpj.review');
});

require __DIR__.'/auth.php';
