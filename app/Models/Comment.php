<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'fund_request_id',
        'user_id',
        'type',
        'body',
    ];

    public function fundRequest()
    {
        return $this->belongsTo(FundRequest::class);
    }

    // admin/staff yang menulis komentar ini
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
