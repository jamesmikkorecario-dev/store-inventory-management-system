<?php

namespace App\Models;

use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $po_number
 * @property int $supplier_id
 * @property int $created_by
 * @property int|null $approved_by
 * @property string $status
 * @property Carbon $order_date
 * @property Carbon|null $expected_delivery_date
 * @property string|null $notes
 * @property float $total_amount
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $purchase_order_items_count
 * @property int|null $items_count
 * @property int|null $line_count
 * @property int|null $units_ordered
 * @property int|null $units_received
 * @property int|null $ordered_units
 * @property int|null $received_units
 * @property float|null $received_value
 * @property float|null $outstanding_value
 * @property string|null $last_order_date
 * @property-read Collection<int, PurchaseOrderItem> $items
 */
#[Fillable([
    'po_number',
    'supplier_id',
    'created_by',
    'approved_by',
    'status',
    'order_date',
    'expected_delivery_date',
    'notes',
    'total_amount',
    'submitted_at',
    'approved_at',
    'received_at',
    'cancelled_at',
])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Every status in workflow order, mapped to its human readable label.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_PARTIALLY_RECEIVED => 'Partially Received',
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * Allowed status transitions for the purchase order workflow.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_PARTIALLY_RECEIVED => [self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_RECEIVED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Statuses that still expect a delivery.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    /**
     * Statuses where stock can still be booked in.
     *
     * @var list<string>
     */
    public const RECEIVABLE_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Supplier the order is placed with
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * User who raised the order
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who approved the order
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Line items on the order
     *
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Human readable status label.
     */
    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Flux badge colour matching the current status.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'zinc',
            self::STATUS_SUBMITTED => 'sky',
            self::STATUS_APPROVED => 'indigo',
            self::STATUS_PARTIALLY_RECEIVED => 'amber',
            self::STATUS_RECEIVED => 'emerald',
            self::STATUS_CANCELLED => 'rose',
            default => 'zinc',
        };
    }

    /**
     * Whether the order may move into the given status.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Only draft orders may still be edited.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Whether stock can still be booked in against this order.
     */
    public function isReceivable(): bool
    {
        return in_array($this->status, self::RECEIVABLE_STATUSES, true);
    }

    /**
     * Whether the order is still awaiting delivery.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Whether the expected delivery date has passed while stock is still outstanding.
     */
    public function isOverdue(): bool
    {
        if ($this->expected_delivery_date === null) {
            return false;
        }

        if (! in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PARTIALLY_RECEIVED, self::STATUS_SUBMITTED], true)) {
            return false;
        }

        return $this->expected_delivery_date->startOfDay()->isBefore(Carbon::now()->startOfDay());
    }

    /**
     * Total units ordered across every line item.
     */
    public function totalOrdered(): int
    {
        return (int) $this->items->sum('quantity_ordered');
    }

    /**
     * Total units already booked in.
     */
    public function totalReceived(): int
    {
        return (int) $this->items->sum('quantity_received');
    }

    /**
     * Completion percentage of the receiving process.
     */
    public function receivedPercentage(): float
    {
        $ordered = $this->totalOrdered();

        if ($ordered === 0) {
            return 0.0;
        }

        return round(($this->totalReceived() / $ordered) * 100, 1);
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
}
