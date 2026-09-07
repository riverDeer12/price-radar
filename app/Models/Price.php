<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    protected $fillable = [
        'product_id', 'store_id', 'price', 'unit_price', 'best_price_30',
        'anchor_price', 'special_price', 'archive_date',
    ];

    protected $casts = [
        'archive_date' => 'date',
        'price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'best_price_30' => 'decimal:2',
        'anchor_price' => 'decimal:2',
        'special_price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
