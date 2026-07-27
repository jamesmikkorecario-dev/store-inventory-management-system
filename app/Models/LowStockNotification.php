<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'current_stock',
    'threshold',
    'severity',
    'read_at',
    'resolved_at',
])]
class LowStockNotification extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'threshold' => 'integer',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Get the product that has low stock.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
