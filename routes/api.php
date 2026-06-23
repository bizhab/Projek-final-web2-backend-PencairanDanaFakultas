<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileAccessController;
use App\Http\Controllers\Api\FundRequestController;
use App\Http\Controllers\Api\KeuanganController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SAPDF (Sistem Administrasi Pencairan Dana Fakultas)
| FST UIN Alauddin Makassar
|--------------------------------------------------------------------------
*/

// Public routes - tidak perlu auth
// FR-1.1: Login dengan restriksi domain @uin-alauddin.ac.id
Route::post('/login', [AuthController::class, 'login']);

// FR-3.3: Verifikasi tanda tangan digital (publik, bisa diakses via QR Code)
Route::get('/verify/{hash}', [FileAccessController::class, 'verifySignature']);

// Routes yang butuh autentikasi
Route::middleware('auth:sanctum')->group(function () {

    // Auth umum - bisa diakses semua role
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Notifikasi untuk user yang login
    Route::get('/notifications', [FundRequestController::class, 'notifications']);
    Route::post('/notifications/read', [FundRequestController::class, 'markNotificationsRead']);
});

// === AKSES FILE (PDF) - Menggunakan Signed URL (tanpa auth header) ===
Route::middleware('signed')->group(function () {
    Route::get('/files/{fundRequest}/signed-sk/download', [FileAccessController::class, 'downloadSignedSk'])->name('api.files.download-signed-sk');
    Route::get('/files/{fundRequest}/signed-lpj/download', [FileAccessController::class, 'downloadSignedLpj'])->name('api.files.download-signed-lpj');
    Route::get('/files/{fundRequest}/{fileType}', [FileAccessController::class, 'previewFile'])->name('api.files.preview');
});

// Routes yang butuh autentikasi
Route::middleware('auth:sanctum')->group(function () {
    // Routes Organisasi
    // ==========================================
    Route::middleware('role:organization')->group(function () {
        // Lihat daftar dan detail pengajuan milik organisasi ini
        Route::get('/fund-requests', [FundRequestController::class, 'index']);
        Route::get('/fund-requests/{fundRequest}', [FundRequestController::class, 'show']);

        // Ajukan SK baru
        Route::post('/fund-requests', [FundRequestController::class, 'store']);

        // Upload ulang SK yang kena revisi
        Route::post('/fund-requests/{fundRequest}/revisi-sk', [FundRequestController::class, 'revisiSk']);

        // Submit LPJ setelah dana cair
        Route::post('/fund-requests/{fundRequest}/lpj', [FundRequestController::class, 'submitLpj']);

        // Upload ulang LPJ yang kena revisi
        Route::post('/fund-requests/{fundRequest}/revisi-lpj', [FundRequestController::class, 'revisiLpj']);
    });

    // ==========================================
    // Routes Admin/Staff Fakultas (Verifikator & Eksekutif)
    // ==========================================
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // FR-6.2: Dashboard statistik ringkasan
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        // Manajemen pengajuan
        Route::get('/fund-requests', [AdminController::class, 'indexFundRequests']);
        Route::get('/fund-requests/{fundRequest}', [AdminController::class, 'showFundRequest']);

        // FR-3.2 & FR-3.3: Review SK (approve + digital signature / revise / reject)
        Route::post('/fund-requests/{fundRequest}/review-sk', [AdminController::class, 'reviewSk']);

        // Review LPJ (approve / revise dengan komentar rinci)
        Route::post('/fund-requests/{fundRequest}/review-lpj', [AdminController::class, 'reviewLpj']);

        // Manajemen organisasi
        Route::get('/organizations', [AdminController::class, 'organizations']);
        Route::post('/organizations/{user}/reset-password', [AdminController::class, 'resetOrganizationPassword']);

        // FR-6.3: Laporan & ekspor data
        Route::get('/reports', [AdminController::class, 'report']);

        // NFR-4.2: Audit Trail
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
    });

    // ==========================================
    // FR-4.1 & FR-4.2: Routes Keuangan (Lantai 2)
    // ==========================================
    Route::middleware('role:keuangan')->prefix('keuangan')->group(function () {
        // Dashboard keuangan
        Route::get('/dashboard', [KeuanganController::class, 'dashboard']);

        // Antrean verifikasi berkas
        Route::get('/fund-requests', [KeuanganController::class, 'indexFundRequests']);
        Route::post('/fund-requests/{fundRequest}/review-sk', [KeuanganController::class, 'verifikasiReview']);
        Route::post('/fund-requests/{fundRequest}/review-lpj', [KeuanganController::class, 'reviewLpj']);

        // Validasi tracking ID sebelum pencairan
        Route::post('/validate-tracking', [KeuanganController::class, 'validateTracking']);

        // Konfirmasi pencairan dana
        Route::post('/fund-requests/{fundRequest}/disburse', [KeuanganController::class, 'confirmDisbursement']);

        // Riwayat pencairan
        Route::get('/disbursement-history', [KeuanganController::class, 'disbursementHistory']);
    });
});
