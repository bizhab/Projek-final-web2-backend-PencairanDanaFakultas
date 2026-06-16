<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'fund_request_id',
        'item_name',
        'category',
        'quantity',
        'unit_price',
        'total_price',
        'receipt_file',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'  => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function fundRequest()
    {
        return $this->belongsTo(FundRequest::class);
    }
}
