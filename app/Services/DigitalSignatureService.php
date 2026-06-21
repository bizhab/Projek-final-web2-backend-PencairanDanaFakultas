<?php

namespace App\Services;

use App\Models\FundRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FR-3.3: Service untuk menempelkan tanda tangan digital (QR Code)
 * pada file SK PDF saat Wadek 3 meng-ACC.
 *
 * Menggunakan pendekatan watermark QR Code yang berisi hash verifikasi.
 * Untuk produksi, bisa diintegrasikan dengan FPDI atau library PDF lainnya.
 */
class DigitalSignatureService
{
    /**
     * Generate hash tanda tangan digital dan metadata QR.
     * Hash = SHA256 dari gabungan tracking_id + user_id + timestamp.
     */
    public function generateSignature(FundRequest $fundRequest): array
    {
        $timestamp = now()->toIso8601String();
        $payload = implode('|', [
            $fundRequest->tracking_id,
            $fundRequest->user_id,
            $fundRequest->activity_name,
            $timestamp,
            config('app.key'),
        ]);

        $hash = hash('sha256', $payload);

        // Data yang akan di-encode ke QR Code
        $qrData = [
            'tracking_id'   => $fundRequest->tracking_id,
            'organization'  => $fundRequest->user->organization_name,
            'activity'      => $fundRequest->activity_name,
            'approved_at'   => $timestamp,
            'hash'          => $hash,
            'verify_url'    => config('app.url') . '/verify/' . $hash,
        ];

        return [
            'hash'     => $hash,
            'qr_data'  => $qrData,
            'metadata' => [
                'signed_at'     => $timestamp,
                'signed_by'     => 'Wakil Dekan III FST UIN Alauddin Makassar',
                'tracking_id'   => $fundRequest->tracking_id,
                'hash'          => $hash,
            ],
        ];
    }

    /**
     * Verifikasi keaslian tanda tangan berdasarkan hash.
     */
    public function verifySignature(string $hash): ?FundRequest
    {
        return FundRequest::where('signature_hash', $hash)->first();
    }

    /**
     * Simpan informasi tanda tangan digital ke fund_request.
     * Di produksi, di sini kita juga akan memodifikasi PDF menggunakan FPDI.
     */
    public function applySignature(FundRequest $fundRequest, string $signedSkPath): array
    {
        $signatureData = $this->generateSignature($fundRequest);

        $fundRequest->update([
            'signed_sk_file'         => $signedSkPath,
            'digital_signature_path' => $signedSkPath, // Sama dengan signed SK untuk sekarang
            'signature_hash'         => $signatureData['hash'],
        ]);

        return $signatureData;
    }
}
