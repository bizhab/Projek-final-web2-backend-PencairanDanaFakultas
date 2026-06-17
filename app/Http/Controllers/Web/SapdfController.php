<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ExpenseItem;
use App\Models\FundRequest;
use App\Models\User;
use App\Notifications\FundRequestStatusUpdated;
use App\Services\DigitalSignatureService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SapdfController extends Controller
{
    // ============================================================
    // HELPERS: URL File PDF (private storage melalui API endpoint)
    // ============================================================

    private function fileUrl(FundRequest $fr, string $type): ?string
    {
        return $fr->{$type} ? url("/files/{$fr->id}/{$type}") : null;
    }

    private function formatRequest(FundRequest $fr): array
    {
        $fr->loadMissing(['user', 'comments.author']);
        return [
            'id'                  => $fr->id,
            'tracking_id'         => $fr->tracking_id,
            'activity_name'       => $fr->activity_name,
            'activity_date'       => $fr->activity_date?->format('Y-m-d'),
            'activity_type'       => $fr->activity_type,
            'description'         => $fr->description,
            'total_budget'        => $fr->total_budget,
            'total_realization'   => $fr->total_realization,
            'status_sk'           => $fr->status_sk,
            'status_lpj'          => $fr->status_lpj,
            'disbursed_at'        => $fr->disbursed_at,
            'sk_file_url'         => $this->fileUrl($fr, 'sk_file'),
            'rab_file_url'        => $this->fileUrl($fr, 'rab_file'),
            'signed_sk_file_url'  => $fr->signed_sk_file
                ? url("/files/{$fr->id}/signed-sk/download")
                : null,
            'lpj_file_url'        => $this->fileUrl($fr, 'lpj_file'),
            'signed_lpj_file_url' => $fr->signed_lpj_file
                ? url("/files/{$fr->id}/signed-lpj/download")
                : null,
            'user'                => $fr->user ? [
                'id'                => $fr->user->id,
                'name'              => $fr->user->name,
                'organization_name' => $fr->user->organization_name,
            ] : null,
            'comments'            => $fr->comments->map(fn($c) => [
                'id'        => $c->id,
                'body'      => $c->body,
                'type'      => $c->type,
                'author'    => $c->author?->name,
                'created_at'=> $c->created_at?->format('d/m/Y H:i'),
            ])->values()->all(),
        ];
    }

    // ============================================================
    // ORGANISASI — Pengajuan SK & RAB
    // ============================================================

    public function pengajuanIndex(Request $request)
    {
        $items = FundRequest::with(['comments'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn($fr) => $this->formatRequest($fr))
            ->all();

        return Inertia::render('Pengajuan/Index', [
            'pengajuan' => $items,
            'flash'     => session('flash', []),
        ]);
    }

    public function pengajuanStore(Request $request)
    {
        $user = $request->user();

        // Hard block: LPJ belum selesai
        if (FundRequest::hasPendingDisbursementWithoutLpj($user->id)) {
            return back()->withErrors(['activity_name' =>
                'Masih ada dana yang sudah cair namun LPJ belum diajukan. Selesaikan LPJ terlebih dahulu.']);
        }

        if (FundRequest::hasOutstandingLpj($user->id)) {
            return back()->withErrors(['activity_name' =>
                'Masih ada LPJ yang belum disetujui. Selesaikan proses LPJ yang berjalan.']);
        }

        $request->validate([
            'activity_name' => 'required|string|max:255',
            'activity_date' => 'required|date',
            'activity_type' => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'total_budget'  => 'required|numeric|min:0',
            'sk_file'       => 'required|file|mimes:pdf|max:15360',
            'rab_file'      => 'required|file|mimes:pdf|max:15360',
        ]);

        // Validasi SOP H-7
        $activityDate = Carbon::parse($request->activity_date);
        $minDate      = now()->addDays(7)->startOfDay();
        if ($activityDate->lt($minDate)) {
            return back()->withErrors(['activity_date' =>
                'Pengajuan SK harus dilakukan minimal 7 hari sebelum tanggal kegiatan. '
                . 'Tanggal paling cepat: ' . $minDate->format('d/m/Y') . '.']);
        }

        $skPath  = $request->file('sk_file')->store('documents/sk', 'private');
        $rabPath = $request->file('rab_file')->store('documents/rab', 'private');

        FundRequest::create([
            'user_id'         => $user->id,
            'activity_name'   => $request->activity_name,
            'activity_date'   => $request->activity_date,
            'activity_type'   => $request->activity_type,
            'description'     => $request->description,
            'total_budget'    => $request->total_budget,
            'sk_file'         => $skPath,
            'rab_file'        => $rabPath,
            'status_sk'       => 'pending',
            'status_lpj'      => 'none',
            'sk_submitted_at' => now(),
        ]);

        return redirect()->route('pengajuan.index')
            ->with('flash', ['success' => 'Pengajuan SK berhasil dikirim. Menunggu verifikasi Keuangan Lt.2.']);
    }

    public function pengajuanRevisi(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($fundRequest->status_sk !== 'revised') {
            return back()->withErrors(['sk_file' => 'Pengajuan tidak dalam status revisi.']);
        }

        $request->validate([
            'sk_file'  => 'required|file|mimes:pdf|max:15360',
            'rab_file' => 'nullable|file|mimes:pdf|max:15360',
        ]);

        Storage::disk('private')->delete($fundRequest->sk_file);
        $skPath = $request->file('sk_file')->store('documents/sk', 'private');

        $updateData = [
            'sk_file'         => $skPath,
            'status_sk'       => 'pending',
            'sk_submitted_at' => now(),
            'sk_reviewed_at'  => null,
        ];

        if ($request->hasFile('rab_file')) {
            Storage::disk('private')->delete($fundRequest->rab_file);
            $updateData['rab_file'] = $request->file('rab_file')->store('documents/rab', 'private');
        }

        $fundRequest->update($updateData);

        return redirect()->route('pengajuan.index')
            ->with('flash', ['success' => 'Revisi SK berhasil dikirim ulang. Menunggu verifikasi Keuangan Lt.2.']);
    }

    // ============================================================
    // KEUANGAN LT.2 — Verifikasi Berkas
    // ============================================================

    public function verifikasiIndex()
    {
        // Keuangan melihat semua pengajuan (pending, verified, revised, approved, rejected)
        $items = FundRequest::with(['user', 'comments'])
            ->whereIn('status_sk', ['pending', 'verified', 'revised', 'approved', 'rejected'])
            ->latest()
            ->get()
            ->map(fn($fr) => $this->formatRequest($fr))
            ->all();

        return Inertia::render('Verifikasi/Index', [
            'antrean' => $items,
            'flash'   => session('flash', []),
        ]);
    }

    public function verifikasiReview(Request $request, FundRequest $fundRequest)
    {
        $request->validate([
            'action'  => 'required|in:approve,revise',
            'comment' => 'required_if:action,revise|nullable|string',
        ]);

        if ($request->action === 'approve') {
            // Keuangan menyetujui → status menjadi 'verified' (Menunggu ACC Dekanat)
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

            return redirect()->route('verifikasi.index')
                ->with('flash', ['success' => 'Berkas diverifikasi. Pengajuan diteruskan ke Dekanat untuk ACC.']);
        }

        // Kembalikan ke organisasi dengan catatan
        DB::transaction(function () use ($request, $fundRequest) {
            Comment::create([
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

        return redirect()->route('verifikasi.index')
            ->with('flash', ['success' => 'Berkas dikembalikan ke organisasi dengan catatan revisi.']);
    }

    // ============================================================
    // KEUANGAN LT.2 — Pencairan Dana
    // ============================================================

    public function pencairanIndex()
    {
        // SK yang sudah di-ACC dekanat tapi belum cair
        $items = FundRequest::with(['user'])
            ->where('status_sk', 'approved')
            ->whereNull('disbursed_at')
            ->latest()
            ->get()
            ->map(fn($fr) => $this->formatRequest($fr))
            ->all();

        return Inertia::render('Pencairan/Index', [
            'antrean' => $items,
            'flash'   => session('flash', []),
        ]);
    }

    public function pencairanConfirm(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->status_sk !== 'approved') {
            return back()->withErrors(['error' => 'SK belum di-ACC Dekanat.']);
        }

        DB::transaction(function () use ($request, $fundRequest) {
            $fundRequest->update([
                'disbursed_at' => now(),
                'disbursed_by' => $request->user()->id,
            ]);

            AuditLog::record(
                $request->user()->id,
                'disbursed',
                $fundRequest,
                ['disbursed_at' => null],
                ['disbursed_at' => now(), 'disbursed_by' => $request->user()->name]
            );

            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'disbursed'));
        });

        return redirect()->route('pencairan.index')
            ->with('flash', ['success' => "Dana untuk \"{$fundRequest->activity_name}\" berhasil dikonfirmasi cair. Organisasi dapat mengajukan LPJ."]);
    }

    // ============================================================
    // DEKANAT/ADMIN — ACC Pengajuan
    // ============================================================

    public function dekanatIndex()
    {
        // Dekanat HANYA melihat pengajuan yang sudah diverifikasi oleh Keuangan (status 'verified')
        $items = FundRequest::with(['user', 'comments'])
            ->where('status_sk', 'verified')
            ->latest()
            ->get()
            ->map(fn($fr) => $this->formatRequest($fr))
            ->all();

        return Inertia::render('Dekanat/Index', [
            'antrean' => $items,
            'flash'   => session('flash', []),
        ]);
    }

    public function dekanatReview(Request $request, FundRequest $fundRequest)
    {
        $request->validate([
            'action'         => 'required|in:approve,revise,reject',
            'comment'        => 'required_if:action,revise|required_if:action,reject|nullable|string',
            'signed_sk_file' => 'required_if:action,approve|nullable|file|mimes:pdf|max:15360',
        ]);

        if ($request->action === 'approve') {
            DB::transaction(function () use ($request, $fundRequest) {
                $signedPath = $request->file('signed_sk_file')
                    ->store('documents/signed-sk', 'private');

                $signatureService = new DigitalSignatureService();
                $signatureService->applySignature($fundRequest, $signedPath);

                $fundRequest->update([
                    'status_sk'      => 'approved',
                    'sk_reviewed_at' => now(),
                ]);

                AuditLog::record(
                    $request->user()->id, 'approve_sk', $fundRequest,
                    ['status_sk' => 'verified'], ['status_sk' => 'approved']
                );

                $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'sk_approved'));
            });

            return redirect()->route('dekanat.index')
                ->with('flash', ['success' => 'SK berhasil di-ACC. Organisasi mendapat notifikasi untuk mencetak SK.']);
        }

        if ($request->action === 'revise') {
            DB::transaction(function () use ($request, $fundRequest) {
                Comment::create([
                    'fund_request_id' => $fundRequest->id,
                    'user_id'         => $request->user()->id,
                    'type'            => 'sk',
                    'body'            => $request->comment,
                ]);
                $fundRequest->update(['status_sk' => 'revised', 'sk_reviewed_at' => now()]);
                $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'sk_revised'));
            });

            return redirect()->route('dekanat.index')
                ->with('flash', ['success' => 'SK dikembalikan ke organisasi untuk diperbaiki.']);
        }

        if ($request->action === 'reject') {
            DB::transaction(function () use ($request, $fundRequest) {
                Comment::create([
                    'fund_request_id' => $fundRequest->id,
                    'user_id'         => $request->user()->id,
                    'type'            => 'sk',
                    'body'            => $request->comment,
                ]);
                $fundRequest->update(['status_sk' => 'rejected', 'sk_reviewed_at' => now()]);
                $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'sk_rejected'));
            });

            return redirect()->route('dekanat.index')
                ->with('flash', ['success' => 'SK ditolak.']);
        }
    }

    // ============================================================
    // DEKANAT/ADMIN — Kelola Organisasi
    // ============================================================

    public function organisasiIndex()
    {
        $orgs = User::where('role', 'organization')
            ->withCount('fundRequests')
            ->get()
            ->map(fn($u) => [
                'id'                  => $u->id,
                'name'                => $u->name,
                'email'               => $u->email,
                'organization_name'   => $u->organization_name,
                'fund_requests_count' => $u->fund_requests_count,
            ])->all();

        return Inertia::render('Organisasi/Index', [
            'organisasi' => $orgs,
            'flash'      => session('flash', []),
        ]);
    }

    public function organisasiResetPassword(Request $request, User $user)
    {
        if ($user->role !== 'organization') {
            abort(403, 'Hanya bisa reset password akun organisasi.');
        }

        $request->validate([
            'new_password'              => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        $user->update(['password' => bcrypt($request->new_password)]);
        $user->tokens()->delete();

        return redirect()->route('organisasi.index')
            ->with('flash', ['success' => "Password {$user->name} berhasil direset."]);
    }

    // ============================================================
    // LPJ — Semua Role (Org upload, Admin/Keuangan review)
    // ============================================================

    public function lpjIndex(Request $request)
    {
        $user = $request->user();
        $role = $user->role;

        if ($role === 'organization') {
            // Organisasi: lihat semua pengajuan yang SK-nya sudah approved
            // (bisa ajukan LPJ setelah kegiatan berlangsung)
            $items = FundRequest::with(['comments', 'expenseItems'])
                ->where('user_id', $user->id)
                ->where('status_sk', 'approved')
                ->latest()
                ->get()
                ->map(fn($fr) => array_merge($this->formatRequest($fr), [
                    'can_submit_lpj'   => $fr->status_sk === 'approved'
                                          && in_array($fr->status_lpj, ['none', 'revised']),
                    'activity_passed'  => \Carbon\Carbon::parse($fr->activity_date)->isPast(),
                ]))
                ->all();
        } else {
            // Admin/Keuangan: lihat semua LPJ pending untuk di-review
            $items = FundRequest::with(['user', 'comments', 'expenseItems'])
                ->where('status_lpj', 'pending')
                ->latest()
                ->get()
                ->map(fn($fr) => $this->formatRequest($fr))
                ->all();
        }

        return Inertia::render('Lpj/Index', [
            'daftarLpj' => $items,
            'flash'     => session('flash', []),
        ]);
    }

    public function lpjStore(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        // Syarat LPJ: SK sudah di-ACC
        if ($fundRequest->status_sk !== 'approved') {
            return back()->withErrors(['lpj_file' => 'LPJ hanya bisa diajukan setelah SK disetujui (di-ACC) oleh Dekanat.']);
        }

        if (!in_array($fundRequest->status_lpj, ['none', 'revised'])) {
            return back()->withErrors(['lpj_file' => 'LPJ untuk pengajuan ini sudah diajukan sebelumnya.']);
        }

        $request->validate([
            'lpj_file'                   => 'required|file|mimes:pdf|max:15360',
            'expense_items'              => 'required|array|min:1',
            'expense_items.*.item_name'  => 'required|string|max:255',
            'expense_items.*.quantity'   => 'required|integer|min:1',
            'expense_items.*.unit_price' => 'required|numeric|min:0',
            'expense_items.*.category'   => 'nullable|string',
            'expense_items.*.notes'      => 'nullable|string',
        ]);

        $totalRealization = collect($request->expense_items)
            ->sum(fn($i) => $i['quantity'] * $i['unit_price']);

        if ($fundRequest->total_budget && $totalRealization > $fundRequest->total_budget) {
            return back()->withErrors(['lpj_file' =>
                'Total realisasi (Rp ' . number_format($totalRealization, 0, ',', '.') . ') '
                . 'melebihi anggaran (Rp ' . number_format($fundRequest->total_budget, 0, ',', '.') . ').']);
        }

        DB::transaction(function () use ($request, $fundRequest, $totalRealization) {
            if ($fundRequest->lpj_file) {
                Storage::disk('private')->delete($fundRequest->lpj_file);
            }

            $lpjPath = $request->file('lpj_file')->store('documents/lpj', 'private');

            $fundRequest->update([
                'lpj_file'          => $lpjPath,
                'status_lpj'        => 'pending',
                'total_realization' => $totalRealization,
                'lpj_submitted_at'  => now(),
                'lpj_reviewed_at'   => null,
            ]);

            $fundRequest->expenseItems()->delete();
            foreach ($request->expense_items as $item) {
                ExpenseItem::create([
                    'fund_request_id' => $fundRequest->id,
                    'item_name'       => $item['item_name'],
                    'category'        => $item['category'] ?? null,
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                    'total_price'     => $item['quantity'] * $item['unit_price'],
                    'notes'           => $item['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('lpj.index')
            ->with('flash', ['success' => 'LPJ berhasil dikirim. Menunggu review dari fakultas.']);
    }

    public function lpjRevisi(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id || $fundRequest->status_lpj !== 'revised') {
            abort(403);
        }

        $request->validate([
            'lpj_file'                   => 'required|file|mimes:pdf|max:15360',
            'expense_items'              => 'required|array|min:1',
            'expense_items.*.item_name'  => 'required|string|max:255',
            'expense_items.*.quantity'   => 'required|integer|min:1',
            'expense_items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $totalRealization = collect($request->expense_items)
            ->sum(fn($i) => $i['quantity'] * $i['unit_price']);

        DB::transaction(function () use ($request, $fundRequest, $totalRealization) {
            Storage::disk('private')->delete($fundRequest->lpj_file);
            $lpjPath = $request->file('lpj_file')->store('documents/lpj', 'private');

            $fundRequest->update([
                'lpj_file'          => $lpjPath,
                'status_lpj'        => 'pending',
                'total_realization' => $totalRealization,
                'lpj_submitted_at'  => now(),
                'lpj_reviewed_at'   => null,
            ]);

            $fundRequest->expenseItems()->delete();
            foreach ($request->expense_items as $item) {
                ExpenseItem::create([
                    'fund_request_id' => $fundRequest->id,
                    'item_name'       => $item['item_name'],
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                    'total_price'     => $item['quantity'] * $item['unit_price'],
                    'category'        => $item['category'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('lpj.index')
            ->with('flash', ['success' => 'Revisi LPJ berhasil dikirim ulang.']);
    }

    public function lpjReview(Request $request, FundRequest $fundRequest)
    {
        $request->validate([
            'action'  => 'required|in:approve,revise',
            'comment' => 'required_if:action,revise|nullable|string',
        ]);

        if ($request->action === 'approve') {
            DB::transaction(function () use ($request, $fundRequest) {
                $fundRequest->update(['status_lpj' => 'approved', 'lpj_reviewed_at' => now()]);
                AuditLog::record(
                    $request->user()->id, 'approve_lpj', $fundRequest,
                    ['status_lpj' => 'pending'], ['status_lpj' => 'approved']
                );
                $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'lpj_approved'));
            });

            return redirect()->route('lpj.index')
                ->with('flash', ['success' => 'LPJ disetujui. Proses selesai.']);
        }

        DB::transaction(function () use ($request, $fundRequest) {
            Comment::create([
                'fund_request_id' => $fundRequest->id,
                'user_id'         => $request->user()->id,
                'type'            => 'lpj',
                'body'            => $request->comment,
            ]);
            $fundRequest->update(['status_lpj' => 'revised', 'lpj_reviewed_at' => now()]);
            $fundRequest->user->notify(new FundRequestStatusUpdated($fundRequest, 'lpj_revised'));
        });

        return redirect()->route('lpj.index')
            ->with('flash', ['success' => 'LPJ dikembalikan ke organisasi dengan catatan.']);
    }
}
