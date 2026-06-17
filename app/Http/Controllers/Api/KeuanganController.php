<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FundRequest;
use App\Notifications\FundRequestStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-4.1 & FR-4.2: Controller khusus Keuangan Lantai 2
 * Menangani validasi SK, konfirmasi pencairan dana, dan tracking.
 */
class KeuanganController extends Controller
{
    /**
     * FR-4.1: Dashboard Keuangan - daftar SK yang sudah di-ACC dan siap dicairkan.
     */
    public function dashboard()
    {
        $stats = [
            'menunggu_pencairan' => FundRequest::where('status_sk', 'approved')
                ->whereNull('disbursed_at')->count(),
            'sudah_dicairkan'    => FundRequest::whereNotNull('disbursed_at')->count(),
            'total_dicairkan'    => FundRequest::whereNotNull('disbursed_at')
                ->sum('total_budget'),
        ];

        $pendingDisbursements = FundRequest::with('user')
            ->where('status_sk', 'approved')
            ->whereNull('disbursed_at')
            ->latest('sk_reviewed_at')
            ->paginate(15);

        return response()->json([
            'stats'                => $stats,
            'pending_disbursements' => $pendingDisbursements,
        ]);
    }

    /**
     * FR-4.1: Daftar pengajuan untuk keperluan verifikasi berkas oleh Keuangan.
     */
    public function indexFundRequests(Request $request)
    {
        $query = FundRequest::with('user')
            ->whereIn('status_sk', ['pending', 'verified', 'revised', 'approved', 'rejected'])
            ->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('activity_name', 'like', "%{$search}%")
                    ->orWhere('tracking_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn($u) => $u->where('organization_name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * FR-4.1: Review berkas SK oleh Keuangan (Verifikasi awal sebelum ke Dekanat).
     */
    public function verifikasiReview(Request $request, FundRequest $fundRequest)
    {
        $request->validate([
            'action'  => 'required|in:approve,revise',
            'comment' => 'required_if:action,revise|nullable|string',
        ]);

        if ($request->action === 'approve') {
            DB::transaction(function () use ($request, $fundRequest) {
                $fundRequest->update([
                    'status_sk' => 'verified',
                ]);

                AuditLog::record(
                    $request->user()->id,
                    'keuangan_verified',
                    $fundRequest,
                    ['status_sk' => 'pending'],
                    ['status_sk' => 'verified', 'verified_by' => $request->user()->name]
                );
            });

            return response()->json(['message' => 'Berkas diverifikasi. Pengajuan diteruskan ke Dekanat untuk ACC.']);
        }

        // Action: revise
        DB::transaction(function () use ($request, $fundRequest) {
            \App\Models\Comment::create([
                'fund_request_id' => $fundRequest->id,
                'user_id'         => $request->user()->id,
                'type'            => 'sk',
                'body'            => $request->comment,
            ]);

            $fundRequest->update([
                'status_sk'      => 'revised',
                'sk_reviewed_at' => now(),
            ]);

            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'sk_revised'));
        });

        return response()->json(['message' => 'Berkas dikembalikan ke organisasi dengan catatan revisi.']);
    }

    /**
     * FR-4.1: Validasi nomor SK / Tracking ID.
     * Keuangan bisa mencari pengajuan berdasarkan tracking_id.
     */
    public function validateTracking(Request $request)
    {
        $request->validate([
            'tracking_id' => 'required|string',
        ]);

        $fundRequest = FundRequest::with('user')
            ->where('tracking_id', $request->tracking_id)
            ->first();

        if (! $fundRequest) {
            return response()->json([
                'message' => 'Tracking ID tidak ditemukan.',
                'valid'   => false,
            ], 404);
        }

        if ($fundRequest->status_sk !== 'approved') {
            return response()->json([
                'message' => 'SK belum disetujui oleh pihak Fakultas.',
                'valid'   => false,
                'status'  => $fundRequest->sk_status_label,
            ], 422);
        }

        if ($fundRequest->disbursed_at) {
            return response()->json([
                'message'      => 'Dana untuk SK ini sudah dicairkan sebelumnya.',
                'valid'        => false,
                'disbursed_at' => $fundRequest->disbursed_at,
            ], 422);
        }

        return response()->json([
            'message'      => 'SK valid dan siap dicairkan.',
            'valid'        => true,
            'fund_request' => [
                'id'                => $fundRequest->id,
                'tracking_id'      => $fundRequest->tracking_id,
                'activity_name'    => $fundRequest->activity_name,
                'activity_date'    => $fundRequest->activity_date,
                'total_budget'     => $fundRequest->total_budget,
                'organization'     => $fundRequest->user->organization_name,
                'sk_reviewed_at'   => $fundRequest->sk_reviewed_at,
                'signature_hash'   => $fundRequest->signature_hash,
            ],
        ]);
    }

    /**
     * FR-4.2: Konfirmasi "Dana Telah Diserahkan".
     * FR-4.3: Trigger otomatis - masa berlaku LPJ dimulai.
     * NFR-4.1: Menggunakan DB Transaction.
     * NFR-4.2: Audit Trail.
     */
    public function confirmDisbursement(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->status_sk !== 'approved') {
            return response()->json([
                'message' => 'SK belum disetujui, tidak bisa mencairkan dana.',
            ], 422);
        }

        if ($fundRequest->disbursed_at) {
            return response()->json([
                'message' => 'Dana sudah dicairkan sebelumnya pada ' . $fundRequest->disbursed_at->translatedFormat('d F Y H:i'),
            ], 422);
        }

        // NFR-4.1: DB Transaction untuk proses krusial
        DB::transaction(function () use ($request, $fundRequest) {
            $oldValues = [
                'disbursed_at' => null,
                'disbursed_by' => null,
            ];

            $fundRequest->update([
                'disbursed_at' => now(),
                'disbursed_by' => $request->user()->id,
            ]);

            $newValues = [
                'disbursed_at' => $fundRequest->disbursed_at,
                'disbursed_by' => $request->user()->id,
            ];

            // NFR-4.2: Audit Trail
            AuditLog::record(
                $request->user()->id,
                'confirm_disbursement',
                $fundRequest,
                $oldValues,
                $newValues
            );

            // Notifikasi ke organisasi bahwa dana sudah cair
            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'disbursed'));
        });

        return response()->json([
            'message'      => "Dana untuk kegiatan \"{$fundRequest->activity_name}\" telah dikonfirmasi cair. Organisasi sekarang dapat mengajukan LPJ.",
            'disbursed_at' => $fundRequest->fresh()->disbursed_at,
        ]);
    }

    /**
     * Riwayat pencairan dana yang sudah dikonfirmasi.
     */
    public function disbursementHistory(Request $request)
    {
        $query = FundRequest::with('user')
            ->whereNotNull('disbursed_at')
            ->latest('disbursed_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tracking_id', 'like', "%{$search}%")
                    ->orWhere('activity_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn($u) => $u->where('organization_name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Review LPJ oleh Keuangan: verifikasi atau kembalikan dengan komentar revisi.
     * Jika diverifikasi → status menjadi 'verified' → diteruskan ke Admin/Dekanat untuk ACC final.
     */
    public function reviewLpj(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->status_lpj !== 'pending') {
            return response()->json([
                'message' => 'LPJ ini tidak sedang dalam antrian review.',
            ], 422);
        }

        $request->validate([
            'action'  => 'required|in:approve,revise',
            'comment' => 'required_if:action,revise|nullable|string',
        ]);

        if ($request->action === 'approve') {
            DB::transaction(function () use ($request, $fundRequest) {
                // Keuangan memverifikasi → status 'verified', belum final
                $fundRequest->update([
                    'status_lpj' => 'verified',
                ]);

                AuditLog::record(
                    $request->user()->id,
                    'keuangan_verified_lpj',
                    $fundRequest,
                    ['status_lpj' => 'pending'],
                    ['status_lpj' => 'verified', 'verified_by' => $request->user()->name]
                );
            });

            return response()->json(['message' => 'LPJ diverifikasi. Diteruskan ke Admin/Dekanat untuk ACC final.']);
        }

        // Action: revise — kembalikan LPJ dengan catatan rinci
        DB::transaction(function () use ($request, $fundRequest) {
            \App\Models\Comment::create([
                'fund_request_id' => $fundRequest->id,
                'user_id'         => $request->user()->id,
                'type'            => 'lpj',
                'body'            => $request->comment,
            ]);

            $fundRequest->update([
                'status_lpj'      => 'revised',
                'lpj_reviewed_at' => now(),
            ]);

            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'lpj_revised'));
        });

        return response()->json(['message' => 'LPJ dikembalikan ke organisasi dengan catatan revisi.']);
    }
}
