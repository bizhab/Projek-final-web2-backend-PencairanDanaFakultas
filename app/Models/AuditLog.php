<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    /**
     * Helper method: log aksi krusial ke audit trail.
     */
    public static function record(
        int $userId,
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): self {
        return self::create([
            'user_id'         => $userId,
            'action'          => $action,
            'auditable_type'  => get_class($auditable),
            'auditable_id'    => $auditable->id,
            'old_values'      => $oldValues,
            'new_values'      => $newValues,
            'ip_address'      => request()->ip(),
            'user_agent'      => request()->userAgent(),
        ]);
    }
}
