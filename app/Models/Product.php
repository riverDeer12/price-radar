<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'chain_id', 'product_code', 'barcode', 'name', 'brand', 'category', 'unit', 'quantity',
    ];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
