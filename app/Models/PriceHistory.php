<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    const UPDATED_AT = null;

    protected $table = 'price_history';

    protected $fillable = ['product_id', 'store_id', 'price', 'special_price', 'recorded_date'];

    protected $casts = [
        'recorded_date' => 'date',
        'price' => 'decimal:2',
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
