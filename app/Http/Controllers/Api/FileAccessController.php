<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundRequest;
use App\Services\DigitalSignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * NFR-1.3: Controller untuk akses file PDF via middleware (bukan direct public URL).
 * FR-3.1: Preview PDF inline di browser.
 * FR-3.4: Download SK final bertanda tangan digital.
 */
class FileAccessController extends Controller
{
    /**
     * FR-3.1: Preview/stream PDF inline di browser.
     * NFR-1.1: Data isolation - cek kepemilikan file.
     */
    public function previewFile(Request $request, FundRequest $fundRequest, string $fileType)
    {
        // Keamanan dijamin oleh middleware 'signed' (Signed URL)

        $allowedTypes = ['sk', 'rab', 'lpj', 'signed_sk', 'sk_file', 'rab_file', 'lpj_file', 'signed_sk_file', 'signed_lpj', 'signed_lpj_file'];
        if (! in_array($fileType, $allowedTypes)) {
            return response()->json(['message' => 'Jenis file tidak valid.'], 400);
        }

        $filePath = match ($fileType) {
            'sk', 'sk_file'               => $fundRequest->sk_file,
            'rab', 'rab_file'             => $fundRequest->rab_file,
            'lpj', 'lpj_file'             => $fundRequest->lpj_file,
            'signed_sk', 'signed_sk_file' => $fundRequest->signed_sk_file,
            'signed_lpj', 'signed_lpj_file' => $fundRequest->signed_lpj_file,
        };

        if (! $filePath || ! Storage::disk('private')->exists($filePath)) {
            // Fallback ke public disk untuk backward compatibility
            if (! $filePath || ! Storage::disk('public')->exists($filePath)) {
                return response()->json(['message' => 'File tidak ditemukan.'], 404);
            }
            $fullPath = Storage::disk('public')->path($filePath);
        } else {
            $fullPath = Storage::disk('private')->path($filePath);
        }

        // NFR-1.2: Validasi MIME-type
        $mimeType = mime_content_type($fullPath);
        if ($mimeType !== 'application/pdf') {
            return response()->json(['message' => 'File bukan PDF yang valid.'], 422);
        }

        // Return file untuk preview inline di browser
        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
        ]);
    }

    /**
     * FR-3.4: Download SK final yang bertanda tangan digital.
     */
    public function downloadSignedSk(Request $request, FundRequest $fundRequest)
    {
        // Keamanan dijamin oleh middleware 'signed' (Signed URL)

        if (! $fundRequest->signed_sk_file) {
            return response()->json(['message' => 'SK bertanda tangan belum tersedia.'], 404);
        }

        // Coba private disk dulu, lalu fallback ke public
        if (Storage::disk('private')->exists($fundRequest->signed_sk_file)) {
            $fullPath = Storage::disk('private')->path($fundRequest->signed_sk_file);
        } elseif (Storage::disk('public')->exists($fundRequest->signed_sk_file)) {
            $fullPath = Storage::disk('public')->path($fundRequest->signed_sk_file);
        } else {
            return response()->json(['message' => 'File tidak ditemukan.'], 404);
        }

        $fileName = "SK_Signed_{$fundRequest->tracking_id}.pdf";

        return response()->download($fullPath, $fileName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Download LPJ final yang bertanda tangan/ACC.
     */
    public function downloadSignedLpj(Request $request, FundRequest $fundRequest)
    {
        // Keamanan dijamin oleh middleware 'signed' (Signed URL)

        if (! $fundRequest->signed_lpj_file) {
            return response()->json(['message' => 'LPJ bertanda tangan/ACC belum tersedia.'], 404);
        }

        // Coba private disk dulu, lalu fallback ke public
        if (Storage::disk('private')->exists($fundRequest->signed_lpj_file)) {
            $fullPath = Storage::disk('private')->path($fundRequest->signed_lpj_file);
        } elseif (Storage::disk('public')->exists($fundRequest->signed_lpj_file)) {
            $fullPath = Storage::disk('public')->path($fundRequest->signed_lpj_file);
        } else {
            return response()->json(['message' => 'File tidak ditemukan.'], 404);
        }

        $fileName = "LPJ_Signed_{$fundRequest->tracking_id}.pdf";

        return response()->download($fullPath, $fileName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * FR-3.3: Verifikasi keaslian tanda tangan digital berdasarkan hash.
     * Endpoint publik agar bisa di-scan QR Code oleh siapa saja.
     */
    public function verifySignature(Request $request, string $hash)
    {
        $signatureService = new DigitalSignatureService();
        $fundRequest = $signatureService->verifySignature($hash);

        if (! $fundRequest) {
            return response()->json([
                'valid'   => false,
                'message' => 'Tanda tangan digital tidak valid atau tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'valid'   => true,
            'message' => 'Tanda tangan digital valid.',
            'data'    => [
                'tracking_id'      => $fundRequest->tracking_id,
                'activity_name'    => $fundRequest->activity_name,
                'organization'     => $fundRequest->user->organization_name,
                'status_sk'        => $fundRequest->sk_status_label,
                'approved_at'      => $fundRequest->sk_reviewed_at,
                'signature_hash'   => $fundRequest->signature_hash,
            ],
        ]);
    }
}
