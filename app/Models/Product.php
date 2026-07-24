<?php

namespace App\Models;

use App\Services\LowStockNotificationService;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $category_id
 * @property string $sku
 * @property string $identifier
 * @property string $name
 * @property string|null $description
 * @property string|null $image_path
 * @property float $cost_price
 * @property float $selling_price
 * @property int $current_stock
 * @property int $minimum_stock
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $total_movements
 * @property string|null $last_transaction_date
 * @property int|null $days_since_last_transaction
 */
#[Fillable([
    'supplier_id',
    'category_id',
    'sku',
    'identifier',
    'name',
    'description',
    'image_path',
    'cost_price',
    'selling_price',
    'current_stock',
    'minimum_stock',
    'status',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->identifier)) {
                $product->identifier = self::generateUniqueIdentifier();
            }
        });

        static::created(function (Product $product) {
            app(LowStockNotificationService::class)->handleProductStockUpdate($product);
        });

        static::updated(function (Product $product) {
            if ($product->wasChanged(['current_stock', 'minimum_stock'])) {
                app(LowStockNotificationService::class)->handleProductStockUpdate($product);
            }
        });

        static::deleted(function (Product $product) {
            app(LowStockNotificationService::class)->handleProductStockUpdate($product);
        });

        static::restored(function (Product $product) {
            app(LowStockNotificationService::class)->handleProductStockUpdate($product);
        });
    }

    public static function generateUniqueIdentifier(): string
    {
        do {
            $identifier = 'PRD'.str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('identifier', $identifier)->exists());

        return $identifier;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'current_stock' => 'integer',
            'minimum_stock' => 'integer',
        ];
    }

    /**
     * Supplier of the product
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Category of the product
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Inventory transactions for the product
     *
     * @return HasMany<InventoryTransaction, $this>
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Check if stock level is low
     */
    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->minimum_stock;
    }

    /**
     * Activity log configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the product image URL or a placeholder
     */
    public function imageUrl(): string
    {
        if ($this->image_path) {
            return Storage::disk('public')->url($this->image_path);
        }

        return 'https://placehold.co/400x400/f4f4f5/a1a1aa?text='.urlencode(Str::limit($this->name, 10));
    }
}
