<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ExpenseItem;
use App\Models\FundRequest;
use App\Notifications\FundRequestStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FundRequestController extends Controller
{
    /**
     * Daftar pengajuan milik organisasi yang sedang login.
     * NFR-1.1: Data Isolation - hanya menampilkan data milik user sendiri.
     */
    public function index(Request $request)
    {
        $requests = FundRequest::with(['comments' => function ($q) {
            $q->latest()->take(1); // ambil komentar terbaru saja untuk list view
        }])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return response()->json($requests);
    }

    /**
     * Detail satu pengajuan beserta semua komentar dan rincian nota.
     * NFR-1.1: Data Isolation.
     */
    public function show(Request $request, FundRequest $fundRequest)
    {
        // pastikan organisasi hanya bisa lihat pengajuan miliknya sendiri
        if ($fundRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Tidak memiliki akses ke pengajuan ini.'], 403);
        }

        $fundRequest->load(['comments.author', 'user', 'expenseItems']);

        return response()->json($fundRequest);
    }

    /**
     * Ajukan SK baru.
     * FR-2.2: Validasi SOP - Sistem memblokir submisi jika tanggal kegiatan < H-7 dari hari pengajuan.
     *         Artinya: kegiatan harus minimal 7 hari ke depan dari hari ini.
     * FR-2.3: Unggah SK & RAB dalam format .pdf.
     * FR-5.4: Hard Block - tidak bisa ajukan SK baru jika ada LPJ yang belum selesai.
     * NFR-1.2: Validasi MIME-type ketat.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // FR-5.4: Hard Block - cek apakah ada LPJ yang belum diselesaikan
        if (FundRequest::hasPendingDisbursementWithoutLpj($user->id)) {
            return response()->json([
                'message' => 'Anda tidak dapat mengajukan SK baru karena masih ada dana yang sudah cair tetapi LPJ belum diajukan. Silakan selesaikan LPJ terlebih dahulu.',
            ], 422);
        }

        if (FundRequest::hasOutstandingLpj($user->id)) {
            return response()->json([
                'message' => 'Anda tidak dapat mengajukan SK baru karena masih ada LPJ yang belum disetujui. Silakan selesaikan proses LPJ yang sedang berjalan.',
            ], 422);
        }

        $request->validate([
            'activity_name' => 'required|string|max:255',
            'activity_date' => 'required|date',
            'activity_type' => 'nullable|string|max:100',
            'fund_type'     => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'total_budget'  => 'required|numeric|min:0',
            // NFR-1.2 & NFR-2.1: Validasi MIME-type ketat + batas 15MB
            'sk_file'       => 'required|file|mimes:pdf|max:15360',
            'rab_file'      => 'required|file|mimes:pdf|max:15360',
        ]);

        // FR-2.2: Validasi SOP - kegiatan harus minimal 7 hari ke depan
        $activityDate = \Carbon\Carbon::parse($request->activity_date);
        $minDate = now()->addDays(7)->startOfDay();

        if ($activityDate->lt($minDate)) {
            return response()->json([
                'message' => 'Pengajuan SK harus dilakukan minimal 7 hari sebelum tanggal kegiatan. '
                    . 'Tanggal kegiatan paling cepat: ' . $minDate->translatedFormat('d F Y') . '.',
            ], 422);
        }

        // NFR-1.3: Simpan file di private storage
        $skPath  = $request->file('sk_file')->store('documents/sk', 'private');
        $rabPath = $request->file('rab_file')->store('documents/rab', 'private');

        $fundRequest = FundRequest::create([
            'user_id'          => $user->id,
            'activity_name'    => $request->activity_name,
            'activity_date'    => $request->activity_date,
            'activity_type'    => $request->activity_type,
            'fund_type'        => $request->fund_type,
            'description'      => $request->description,
            'total_budget'     => $request->total_budget,
            'sk_file'          => $skPath,
            'rab_file'         => $rabPath,
            'status_sk'        => 'pending', // FR-2.4: langsung ke Menunggu Verifikasi
            'sk_submitted_at'  => now(),
        ]);

        return response()->json([
            'message'      => 'SK berhasil diajukan. Silakan menunggu review dari pihak fakultas.',
            'fund_request' => $fundRequest,
        ], 201);
    }

    /**
     * Upload ulang SK yang perlu diperbaiki (status harus 'revised').
     */
    public function revisiSk(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Tidak memiliki akses.'], 403);
        }

        if ($fundRequest->status_sk !== 'revised') {
            return response()->json([
                'message' => 'Pengajuan ini tidak dalam status revisi.',
            ], 422);
        }

        $request->validate([
            'sk_file'  => 'required|file|mimes:pdf|max:15360',
            'rab_file' => 'nullable|file|mimes:pdf|max:15360',
        ]);

        // hapus file lama
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

        return response()->json([
            'message' => 'Revisi SK berhasil diajukan kembali.',
        ]);
    }

    /**
     * Upload LPJ setelah dana cair.
     * FR-4.3: LPJ baru bisa diajukan setelah dana sudah cair.
     * FR-5.1: Input rincian nota belanja secara dinamis.
     * FR-5.2: Upload PDF gabungan LPJ + scan nota.
     * FR-5.3: Validasi total realisasi <= total budget.
     */
    public function submitLpj(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Tidak memiliki akses.'], 403);
        }

        if (! $fundRequest->canSubmitLpj()) {
            if ($fundRequest->status_sk !== 'approved') {
                return response()->json([
                    'message' => 'LPJ hanya bisa diajukan setelah SK disetujui oleh fakultas.',
                ], 422);
            }
            if (! $fundRequest->disbursed_at) {
                return response()->json([
                    'message' => 'LPJ hanya bisa diajukan setelah dana dicairkan oleh pihak keuangan Lt.2.',
                ], 422);
            }
            return response()->json([
                'message' => 'LPJ tidak dapat diajukan untuk pengajuan ini saat ini.',
            ], 422);
        }

        $request->validate([
            // NFR-2.1: Batas max 15MB
            'lpj_file'              => 'required|file|mimes:pdf|max:15360',
            // FR-5.1: Rincian nota belanja
            'expense_items'         => 'required|array|min:1',
            'expense_items.*.item_name'  => 'required|string|max:255',
            'expense_items.*.category'   => 'nullable|string|max:100',
            'expense_items.*.quantity'   => 'required|integer|min:1',
            'expense_items.*.unit_price' => 'required|numeric|min:0',
            'expense_items.*.notes'      => 'nullable|string',
        ]);

        // FR-5.3: Hitung total realisasi dan validasi
        $totalRealization = 0;
        foreach ($request->expense_items as $item) {
            $totalRealization += $item['quantity'] * $item['unit_price'];
        }

        if ($fundRequest->total_budget && $totalRealization > $fundRequest->total_budget) {
            return response()->json([
                'message' => 'Total realisasi belanja (Rp ' . number_format($totalRealization, 0, ',', '.')
                    . ') melebihi anggaran yang disetujui (Rp ' . number_format($fundRequest->total_budget, 0, ',', '.')
                    . '). Mohon periksa kembali rincian nota.',
            ], 422);
        }

        // NFR-4.1: DB Transaction untuk proses krusial
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

            // FR-5.1: Hapus expense items lama lalu masukkan yang baru
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

        return response()->json([
            'message'           => 'LPJ berhasil diajukan. Menunggu review dari pihak fakultas.',
            'total_realization' => $totalRealization,
        ]);
    }

    /**
     * Upload ulang LPJ yang perlu diperbaiki.
     */
    public function revisiLpj(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Tidak memiliki akses.'], 403);
        }

        if ($fundRequest->status_lpj !== 'revised') {
            return response()->json([
                'message' => 'LPJ tidak dalam status revisi.',
            ], 422);
        }

        $request->validate([
            'lpj_file'              => 'required|file|mimes:pdf|max:15360',
            'expense_items'         => 'required|array|min:1',
            'expense_items.*.item_name'  => 'required|string|max:255',
            'expense_items.*.category'   => 'nullable|string|max:100',
            'expense_items.*.quantity'   => 'required|integer|min:1',
            'expense_items.*.unit_price' => 'required|numeric|min:0',
            'expense_items.*.notes'      => 'nullable|string',
        ]);

        // FR-5.3: Validasi total realisasi
        $totalRealization = 0;
        foreach ($request->expense_items as $item) {
            $totalRealization += $item['quantity'] * $item['unit_price'];
        }

        if ($fundRequest->total_budget && $totalRealization > $fundRequest->total_budget) {
            return response()->json([
                'message' => 'Total realisasi belanja melebihi anggaran yang disetujui.',
            ], 422);
        }

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
                    'category'        => $item['category'] ?? null,
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                    'total_price'     => $item['quantity'] * $item['unit_price'],
                    'notes'           => $item['notes'] ?? null,
                ]);
            }
        });

        return response()->json([
            'message' => 'Revisi LPJ berhasil diajukan kembali.',
        ]);
    }

    /**
     * FR-6.1: Notifikasi milik user yang sedang login.
     */
    public function notifications(Request $request)
    {
        $notifications = $request->user()->notifications()->paginate(15);

        return response()->json($notifications);
    }

    /**
     * Tandai semua notifikasi sebagai sudah dibaca.
     */
    public function markNotificationsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'Semua notifikasi sudah ditandai dibaca.']);
    }
}
