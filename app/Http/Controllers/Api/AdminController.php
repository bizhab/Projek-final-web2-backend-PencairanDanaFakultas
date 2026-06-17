<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\FundRequest;
use App\Models\User;
use App\Notifications\FundRequestStatusUpdated;
use App\Services\DigitalSignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    /**
     * FR-6.2: Dashboard Fakultas - Counter jumlah antrean, revisi, dan total dana terserap.
     */
    public function dashboard(Request $request)
    {
        // Count berdasarkan status SK
        $skStats = [
            'total'    => FundRequest::count(),
            'pending'  => FundRequest::where('status_sk', 'pending')->count(),
            'revised'  => FundRequest::where('status_sk', 'revised')->count(),
            'approved' => FundRequest::where('status_sk', 'approved')->count(),
            'rejected' => FundRequest::where('status_sk', 'rejected')->count(),
        ];

        // Count LPJ
        $lpjStats = [
            'pending'  => FundRequest::where('status_lpj', 'pending')->count(),
            'revised'  => FundRequest::where('status_lpj', 'revised')->count(),
            'approved' => FundRequest::where('status_lpj', 'approved')->count(),
        ];

        // FR-6.2: Total dana terserap
        $totalDanaApproved = FundRequest::where('status_sk', 'approved')->sum('total_budget');
        $totalDanaCair     = FundRequest::whereNotNull('disbursed_at')->sum('total_budget');
        $totalRealisasi    = FundRequest::where('status_lpj', 'approved')->sum('total_realization');

        // Pengajuan terbaru (5 terakhir) untuk widget dashboard
        $recentRequests = FundRequest::with('user')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'tracking_id'   => $r->tracking_id,
                'activity_name'  => $r->activity_name,
                'organization'   => $r->user->organization_name,
                'total_budget'   => $r->total_budget,
                'status_sk'      => $r->status_sk,
                'status_lpj'     => $r->status_lpj,
                'overall_status' => $r->overall_status,
                'created_at'     => $r->created_at,
            ]);

        // Kegiatan bulan ini untuk kalender
        $upcomingActivities = FundRequest::where('activity_date', '>=', now()->startOfMonth())
            ->where('activity_date', '<=', now()->endOfMonth())
            ->select('id', 'tracking_id', 'activity_name', 'activity_date', 'status_sk')
            ->get();

        return response()->json([
            'sk_stats'             => $skStats,
            'lpj_stats'            => $lpjStats,
            'dana_stats'           => [
                'total_approved'  => $totalDanaApproved,
                'total_cair'      => $totalDanaCair,
                'total_realisasi' => $totalRealisasi,
            ],
            'recent_requests'      => $recentRequests,
            'upcoming_activities'  => $upcomingActivities,
        ]);
    }

    /**
     * Daftar semua pengajuan dengan filter dan pencarian.
     */
    public function indexFundRequests(Request $request)
    {
        $query = FundRequest::with('user')->latest();

        // Filter berdasarkan status
        if ($request->filled('status_sk')) {
            $query->where('status_sk', $request->status_sk);
        }

        if ($request->filled('status_lpj')) {
            $statuses = explode(',', $request->status_lpj);
            if (count($statuses) > 1) {
                $query->whereIn('status_lpj', $statuses);
            } else {
                $query->where('status_lpj', $request->status_lpj);
            }
        }

        // Cari berdasarkan nama kegiatan, nama organisasi, atau tracking ID
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('activity_name', 'like', "%{$search}%")
                    ->orWhere('tracking_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn($u) => $u->where('organization_name', 'like', "%{$search}%"));
            });
        }

        // Filter berdasarkan organisasi
        if ($request->filled('organization_id')) {
            $query->where('user_id', $request->organization_id);
        }

        $requests = $query->paginate(15);

        return response()->json($requests);
    }

    /**
     * Detail pengajuan lengkap dengan semua komentar, expense items, dan audit trail.
     */
    public function showFundRequest(FundRequest $fundRequest)
    {
        $fundRequest->load(['user', 'comments.author', 'expenseItems', 'auditLogs.user']);

        return response()->json($fundRequest);
    }

    /**
     * ACC Dekanat: Approve SK yang sudah diverifikasi oleh Keuangan Lt.2.
     * Dekanat hanya memberikan ACC (approve) + upload SK ber-TTD.
     * Pengembalian berkas (revise/reject) adalah tugas Keuangan, bukan Dekanat.
     * FR-3.3: Digital Signature saat ACC.
     * NFR-4.1: DB Transaction.
     * NFR-4.2: Audit Trail.
     */
    public function reviewSk(Request $request, FundRequest $fundRequest)
    {
        // Dekanat hanya menerima SK yang sudah diverifikasi oleh Keuangan
        if (! in_array($fundRequest->status_sk, ['verified'])) {
            return response()->json([
                'message' => 'SK ini belum diverifikasi oleh Keuangan Lt.2, atau sudah diproses.',
            ], 422);
        }

        $request->validate([
            'action'         => 'required|in:approve',
            'comment'        => 'nullable|string',
            'signed_sk_file' => 'required|file|mimes:pdf|max:15360',
        ]);

        if ($request->action === 'approve') {
            // NFR-4.1: DB Transaction untuk proses krusial
            $signatureData = DB::transaction(function () use ($request, $fundRequest) {
                // NFR-1.3: Simpan di private storage
                $signedPath = $request->file('signed_sk_file')->store('documents/signed-sk', 'private');

                // FR-3.3: Apply Digital Signature
                $signatureService = new DigitalSignatureService();
                $signatureData = $signatureService->applySignature($fundRequest, $signedPath);

                $oldValues = ['status_sk' => $fundRequest->status_sk];

                $fundRequest->update([
                    'status_sk'      => 'approved',
                    'sk_reviewed_at' => now(),
                ]);

                // NFR-4.2: Audit Trail
                AuditLog::record(
                    $request->user()->id,
                    'approve_sk',
                    $fundRequest,
                    $oldValues,
                    ['status_sk' => 'approved', 'signature_hash' => $signatureData['hash']]
                );

                // kirim notifikasi ke organisasi bahwa SK disetujui
                $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'sk_approved'));

                return $signatureData;
            });

            return response()->json([
                'message'        => 'SK berhasil di-ACC. File SK bertanda tangan digital telah diunggah.',
                'tracking_id'   => $fundRequest->tracking_id,
                'signature_hash' => $signatureData['hash'],
                'verify_url'     => $signatureData['qr_data']['verify_url'],
            ]);
        }

        // Dekanat tidak punya hak untuk revise/reject — itu tugas Keuangan Lt.2
        return response()->json([
            'message' => 'Aksi tidak diizinkan. Dekanat hanya dapat memberikan ACC.',
        ], 422);
    }

    /**
     * ACC LPJ oleh Admin/Dekanat.
     * Admin hanya memberikan ACC pada LPJ yang sudah diverifikasi oleh Keuangan.
     * Wajib upload file ACC LPJ yang akan dikirimkan ke organisasi.
     * Pengembalian LPJ (revise) adalah tugas Keuangan, bukan Admin.
     */
    public function reviewLpj(Request $request, FundRequest $fundRequest)
    {
        // Admin hanya menerima LPJ yang sudah diverifikasi oleh Keuangan
        if ($fundRequest->status_lpj !== 'verified') {
            return response()->json([
                'message' => 'LPJ ini belum diverifikasi oleh Keuangan Lt.2, atau sudah diproses.',
            ], 422);
        }

        $request->validate([
            'action'          => 'required|in:approve',
            'signed_lpj_file' => 'required|file|mimes:pdf|max:15360',
        ]);

        $signedPath = $request->file('signed_lpj_file')
            ->store('documents/signed-lpj', 'private');

        DB::transaction(function () use ($request, $fundRequest, $signedPath) {
            $oldValues = ['status_lpj' => $fundRequest->status_lpj];

            $fundRequest->update([
                'status_lpj'      => 'approved',
                'signed_lpj_file' => $signedPath,
                'lpj_reviewed_at' => now(),
            ]);

            AuditLog::record(
                $request->user()->id,
                'approve_lpj',
                $fundRequest,
                $oldValues,
                ['status_lpj' => 'approved', 'signed_lpj_file' => $signedPath]
            );

            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'lpj_approved'));
        });

        return response()->json([
            'message' => 'LPJ berhasil di-ACC. File ACC LPJ telah dikirimkan ke organisasi.',
        ]);
    }

    /**
     * Daftar semua organisasi yang terdaftar (untuk filter dan manajemen).
     */
    public function organizations()
    {
        $organizations = User::where('role', 'organization')
            ->select('id', 'name', 'email', 'organization_name', 'created_at')
            ->withCount('fundRequests')
            ->get();

        return response()->json($organizations);
    }

    /**
     * FR-1.3: Reset password organisasi oleh admin.
     */
    public function resetOrganizationPassword(Request $request, User $user)
    {
        if ($user->role !== 'organization') {
            return response()->json(['message' => 'Hanya bisa reset password akun organisasi.'], 403);
        }

        $request->validate([
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => bcrypt($request->new_password)]);
        $user->tokens()->delete(); // paksa logout semua sesi aktif

        return response()->json(['message' => "Password {$user->organization_name} berhasil direset."]);
    }

    /**
     * FR-6.2 & FR-6.3: Laporan statistik pengajuan per periode dan per organisasi.
     * FR-6.3: Export ke Excel/CSV.
     */
    public function report(Request $request)
    {
        $request->validate([
            'year'   => 'nullable|integer|min:2020|max:2030',
            'month'  => 'nullable|integer|min:1|max:12',
            'format' => 'nullable|in:json,csv',
        ]);

        $query = FundRequest::with('user');

        if ($request->filled('year')) {
            $query->whereYear('created_at', $request->year);
        }

        if ($request->filled('month')) {
            $query->whereMonth('created_at', $request->month);
        }

        $data = $query->get();

        $report = [
            'total'            => $data->count(),
            'total_budget'     => $data->whereIn('status_sk', ['approved'])->sum('total_budget'),
            'total_realization' => $data->where('status_lpj', 'approved')->sum('total_realization'),
            'total_disbursed'  => $data->whereNotNull('disbursed_at')->sum('total_budget'),
            'by_status_sk'     => $data->groupBy('status_sk')->map->count(),
            'by_status_lpj'    => $data->groupBy('status_lpj')->map->count(),
            'by_organization'  => $data->groupBy('user.organization_name')
                ->map(fn($group) => [
                    'count'           => $group->count(),
                    'total_budget'    => $group->whereIn('status_sk', ['approved'])->sum('total_budget'),
                    'total_realization' => $group->where('status_lpj', 'approved')->sum('total_realization'),
                ]),
        ];

        // FR-6.3: Export CSV jika diminta
        if ($request->format === 'csv') {
            return $this->exportCsv($data);
        }

        return response()->json($report);
    }

    /**
     * FR-6.3: Ekspor rekapitulasi ke format CSV (kompatibel Excel).
     */
    private function exportCsv($data)
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="laporan_sapdf_' . now()->format('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');

            // BOM untuk Excel UTF-8 support
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($file, [
                'No',
                'Tracking ID',
                'Organisasi',
                'Nama Kegiatan',
                'Tanggal Kegiatan',
                'Jenis Dana',
                'Anggaran (RAB)',
                'Realisasi',
                'Status SK',
                'Status LPJ',
                'Tanggal Pengajuan',
                'Tanggal Pencairan',
            ]);

            // Data rows
            $no = 1;
            foreach ($data as $item) {
                fputcsv($file, [
                    $no++,
                    $item->tracking_id,
                    $item->user->organization_name ?? '-',
                    $item->activity_name,
                    $item->activity_date?->format('d/m/Y'),
                    $item->fund_type ?? '-',
                    $item->total_budget,
                    $item->total_realization ?? 0,
                    $item->sk_status_label,
                    $item->lpj_status_label,
                    $item->created_at?->format('d/m/Y H:i'),
                    $item->disbursed_at?->format('d/m/Y H:i') ?? '-',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * NFR-4.2: Daftar audit trail untuk admin.
     */
    public function auditLogs(Request $request)
    {
        $query = AuditLog::with('user')->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('fund_request_id')) {
            $query->where('auditable_type', FundRequest::class)
                ->where('auditable_id', $request->fund_request_id);
        }

        return response()->json($query->paginate(20));
    }
}
