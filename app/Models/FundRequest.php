<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FundRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'tracking_id',
        'activity_name',
        'activity_date',
        'activity_type',
        'fund_type',
        'description',
        'total_budget',
        'total_realization',
        'sk_file',
        'rab_file',
        'status_sk',
        'signed_sk_file',
        'digital_signature_path',
        'signature_hash',
        'sk_submitted_at',
        'sk_reviewed_at',
        'disbursed_at',
        'disbursed_by',
        'lpj_file',
        'signed_lpj_file',
        'status_lpj',
        'lpj_submitted_at',
        'lpj_reviewed_at',
    ];

    protected $appends = [
        'sk_file_url',
        'rab_file_url',
        'lpj_file_url',
        'signed_sk_file_url',
        'signed_lpj_file_url',
        'sk_status_label',
        'lpj_status_label',
        'overall_status',
    ];

    protected function casts(): array
    {
        return [
            'activity_date'    => 'date',
            'total_budget'     => 'decimal:2',
            'total_realization' => 'decimal:2',
            'sk_submitted_at'  => 'datetime',
            'sk_reviewed_at'   => 'datetime',
            'disbursed_at'     => 'datetime',
            'lpj_submitted_at' => 'datetime',
            'lpj_reviewed_at'  => 'datetime',
        ];
    }

    /**
     * Boot: otomatis generate tracking_id saat create.
     * Format: SAPDF-YYYY-XXXXX (contoh: SAPDF-2026-00001)
     */
    protected static function booted(): void
    {
        static::creating(function (FundRequest $fr) {
            if (empty($fr->tracking_id)) {
                $year  = now()->year;
                $count = self::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $fr->tracking_id = 'SAPDF-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    // ==========================================
    // RELASI
    // ==========================================

    /** Pengajuan ini milik organisasi mana */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Petugas keuangan yang mencairkan dana */
    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /** Semua komentar terkait pengajuan ini (SK maupun LPJ) */
    public function comments()
    {
        return $this->hasMany(Comment::class)->latest();
    }

    public function skComments()
    {
        return $this->hasMany(Comment::class)->where('type', 'sk')->latest();
    }

    public function lpjComments()
    {
        return $this->hasMany(Comment::class)->where('type', 'lpj')->latest();
    }

    /** FR-5.1: Rincian nota belanja pada LPJ */
    public function expenseItems()
    {
        return $this->hasMany(ExpenseItem::class);
    }

    /** NFR-4.2: Audit trail */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    /**
     * FR-5.4: Cek apakah organisasi punya LPJ yang belum selesai.
     * Jika ya, tidak bisa ajukan SK baru (Hard Block).
     */
    public static function hasOutstandingLpj(int $userId): bool
    {
        return self::where('user_id', $userId)
            ->where('status_sk', 'approved')
            ->where('status_lpj', '!=', 'approved')
            ->where('status_lpj', '!=', 'none') // Ada LPJ yang sedang diproses
            ->exists();
    }

    /**
     * FR-5.4: Cek apakah organisasi punya dana yang sudah cair tapi LPJ belum masuk.
     */
    public static function hasPendingDisbursementWithoutLpj(int $userId): bool
    {
        return self::where('user_id', $userId)
            ->whereNotNull('disbursed_at')
            ->whereIn('status_lpj', ['none'])
            ->exists();
    }

    /**
     * Cek apakah LPJ sudah bisa diajukan.
     * FR-4.3: LPJ baru bisa diajukan setelah dana cair.
     */
    public function canSubmitLpj(): bool
    {
        return $this->status_sk === 'approved'
            && $this->disbursed_at !== null  // Dana sudah cair
            && in_array($this->status_lpj, ['none', 'revised']);
    }

    /**
     * FR-5.3: Validasi total realisasi tidak melebihi budget.
     */
    public function isRealizationOverBudget(): bool
    {
        if (! $this->total_budget) return false;
        $totalRealization = $this->expenseItems()->sum('total_price');
        return $totalRealization > $this->total_budget;
    }

    // ==========================================
    // STATUS LABELS
    // ==========================================

    /** FR-2.4: Label status SK yang user-friendly */
    public function getSkStatusLabelAttribute(): string
    {
        return match ($this->status_sk) {
            'draft'     => 'Draft',
            'pending'   => 'Menunggu Verifikasi Keuangan',
            'verified'  => 'Menunggu ACC Dekanat',
            'revised'   => 'Perlu Perbaikan',
            'approved'  => 'Di-ACC',
            'rejected'  => 'Ditolak',
            'dana_cair' => 'Dana Telah Cair',
            default     => '-',
        };
    }

    public function getLpjStatusLabelAttribute(): string
    {
        return match ($this->status_lpj) {
            'none'     => 'Belum Diajukan',
            'pending'  => 'Menunggu Verifikasi Keuangan',
            'verified' => 'Menunggu ACC Dekanat',
            'revised'  => 'Perlu Perbaikan',
            'approved' => 'Selesai',
            default    => '-',
        };
    }

    /** Status keseluruhan pengajuan untuk tampilan dashboard */
    public function getOverallStatusAttribute(): string
    {
        if ($this->status_lpj === 'approved') return 'selesai';
        if ($this->disbursed_at && $this->status_lpj !== 'approved') return 'menunggu_lpj';
        if ($this->status_sk === 'approved' && ! $this->disbursed_at) return 'menunggu_pencairan';
        if ($this->status_sk === 'rejected') return 'ditolak';
        if ($this->status_sk === 'revised') return 'perlu_revisi_sk';
        if ($this->status_lpj === 'revised') return 'perlu_revisi_lpj';
        if ($this->status_sk === 'verified') return 'menunggu_acc_dekanat';
        if ($this->status_sk === 'draft') return 'draft';
        return 'menunggu_verifikasi';
    }

    // ==========================================
    // FILE URL ACCESSORS (Signed URLs - berlaku 24 jam)
    // ==========================================
    public function getSkFileUrlAttribute()
    {
        if (!$this->sk_file) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.files.preview', now()->addHours(24),
            ['fundRequest' => $this->id, 'fileType' => 'sk_file']
        );
    }

    public function getRabFileUrlAttribute()
    {
        if (!$this->rab_file) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.files.preview', now()->addHours(24),
            ['fundRequest' => $this->id, 'fileType' => 'rab_file']
        );
    }

    public function getLpjFileUrlAttribute()
    {
        if (!$this->lpj_file) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.files.preview', now()->addHours(24),
            ['fundRequest' => $this->id, 'fileType' => 'lpj_file']
        );
    }

    public function getSignedSkFileUrlAttribute()
    {
        if (!$this->signed_sk_file) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.files.download-signed-sk', now()->addHours(24),
            ['fundRequest' => $this->id]
        );
    }

    public function getSignedLpjFileUrlAttribute()
    {
        if (!$this->signed_lpj_file) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.files.download-signed-lpj', now()->addHours(24),
            ['fundRequest' => $this->id]
        );
    }
}
