<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'avatar',
        'organization_name',
        'role',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ==========================================
    // ROLE HELPERS (FR-1.4)
    // ==========================================

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganization(): bool
    {
        return $this->role === 'organization';
    }

    /** FR-4.1: Role khusus Keuangan Lt.2 */
    public function isKeuangan(): bool
    {
        return $this->role === 'keuangan';
    }

    // ==========================================
    // RELASI
    // ==========================================

    /** Satu user organisasi bisa punya banyak pengajuan dana */
    public function fundRequests()
    {
        return $this->hasMany(FundRequest::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /** Audit trail dari aksi user ini */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
