<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Store extends Model
{
    protected $fillable = ['chain_id', 'store_code', 'type', 'address', 'city', 'zipcode'];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }
}
