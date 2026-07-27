<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchase order lifecycle: creation, the status workflow and goods receiving.
 *
 * Receiving is the only step that touches stock. It always routes through
 * {@see InventoryService} so the pessimistic locking, negative stock guard and
 * price snapshots stay in a single place.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Next sequential purchase order number for the given month (PO-YYYYMM-0001).
     */
    public function generatePoNumber(?Carbon $date = null): string
    {
        $prefix = 'PO-'.($date ?? Carbon::now())->format('Ym').'-';

        $attempt = 0;

        do {
            /** @var string|null $latest */
            $latest = PurchaseOrder::withTrashed()
                ->where('po_number', 'like', $prefix.'%')
                ->orderByDesc('po_number')
                ->value('po_number');

            $sequence = $latest === null
                ? 1
                : ((int) substr($latest, strlen($prefix))) + 1;

            $candidate = $prefix.str_pad((string) ($sequence + $attempt), 4, '0', STR_PAD_LEFT);
            $attempt++;
        } while ($attempt < 100 && PurchaseOrder::withTrashed()->where('po_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Create a draft purchase order together with its line items.
     *
     * @param  array{supplier_id: int|string, order_date?: string|null, expected_delivery_date?: string|null, notes?: string|null}  $attributes
     * @param  list<array{product_id?: int|string|null, quantity_ordered?: int|string|null, unit_cost?: float|int|string|null}>  $items
     *
     * @throws RuntimeException
     */
    public function create(array $attributes, array $items, User $creator): PurchaseOrder
    {
        $lines = $this->normalizeItems($items);

        return DB::transaction(function () use ($attributes, $lines, $creator): PurchaseOrder {
            $orderDate = isset($attributes['order_date']) && $attributes['order_date'] !== ''
                ? Carbon::parse($attributes['order_date'])
                : Carbon::now();

            $order = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber($orderDate),
                'supplier_id' => (int) $attributes['supplier_id'],
                'created_by' => $creator->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $orderDate->toDateString(),
                'expected_delivery_date' => $this->nullableDate($attributes['expected_delivery_date'] ?? null),
                'notes' => $attributes['notes'] ?? null,
                'total_amount' => 0,
            ]);

            $this->replaceItems($order, $lines);

            return $order->refresh()->load(['items.product', 'supplier', 'creator']);
        });
    }

    /**
     * Update a draft purchase order and replace its line items.
     *
     * @param  array{supplier_id?: int|string, order_date?: string|null, expected_delivery_date?: string|null, notes?: string|null}  $attributes
     * @param  list<array{product_id?: int|string|null, quantity_ordered?: int|string|null, unit_cost?: float|int|string|null}>  $items
     *
     * @throws RuntimeException
     */
    public function update(PurchaseOrder $order, array $attributes, array $items): PurchaseOrder
    {
        if (! $order->isEditable()) {
            throw new RuntimeException('Only draft purchase orders can be edited.');
        }

        $lines = $this->normalizeItems($items);

        return DB::transaction(function () use ($order, $attributes, $lines): PurchaseOrder {
            $payload = [];

            if (isset($attributes['supplier_id'])) {
                $payload['supplier_id'] = (int) $attributes['supplier_id'];
            }

            if (array_key_exists('order_date', $attributes)) {
                $payload['order_date'] = $this->nullableDate($attributes['order_date']) ?? $order->order_date->toDateString();
            }

            if (array_key_exists('expected_delivery_date', $attributes)) {
                $payload['expected_delivery_date'] = $this->nullableDate($attributes['expected_delivery_date']);
            }

            if (array_key_exists('notes', $attributes)) {
                $payload['notes'] = $attributes['notes'];
            }

            if ($payload !== []) {
                $order->update($payload);
            }

            $this->replaceItems($order, $lines);

            return $order->refresh()->load(['items.product', 'supplier', 'creator']);
        });
    }

    /**
     * Move a draft order into the submitted state.
     *
     * @throws RuntimeException
     */
    public function submit(PurchaseOrder $order): PurchaseOrder
    {
        $this->assertTransition($order, PurchaseOrder::STATUS_SUBMITTED);

        if ($order->items()->count() === 0) {
            throw new RuntimeException('A purchase order needs at least one line item before it can be submitted.');
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        $order->refresh();

        $this->notifications->purchaseOrderSubmitted($order);

        return $order;
    }

    /**
     * Send a submitted order back to draft for corrections.
     *
     * @throws RuntimeException
     */
    public function revertToDraft(PurchaseOrder $order): PurchaseOrder
    {
        $this->assertTransition($order, PurchaseOrder::STATUS_DRAFT);

        $order->update([
            'status' => PurchaseOrder::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        return $order->refresh();
    }

    /**
     * Approve a submitted order so goods can be received against it.
     *
     * @throws RuntimeException
     */
    public function approve(PurchaseOrder $order, User $approver): PurchaseOrder
    {
        $this->assertTransition($order, PurchaseOrder::STATUS_APPROVED);

        $order->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::now(),
        ]);

        $order->refresh();

        $this->notifications->purchaseOrderApproved($order);

        return $order;
    }

    /**
     * Cancel an order that has not been fully received yet.
     *
     * @throws RuntimeException
     */
    public function cancel(PurchaseOrder $order, ?string $reason = null): PurchaseOrder
    {
        $this->assertTransition($order, PurchaseOrder::STATUS_CANCELLED);

        $notes = $order->notes;

        if ($reason !== null && trim($reason) !== '') {
            $notes = trim(($notes === null ? '' : $notes."\n").'Cancelled: '.trim($reason));
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
            'notes' => $notes,
        ]);

        $order->refresh();

        $this->notifications->purchaseOrderCancelled($order);

        return $order;
    }

    /**
     * Book in received goods, creating a stock in transaction per line item.
     *
     * Quantities are keyed by purchase order item id. Lines omitted or set to
     * zero are skipped, and receiving more than the outstanding quantity is
     * rejected before any stock is touched.
     *
     * @param  array<int|string, int|string>  $quantities
     *
     * @throws RuntimeException
     */
    public function receive(PurchaseOrder $order, array $quantities, User $receiver): PurchaseOrder
    {
        if (! $order->isReceivable()) {
            throw new RuntimeException('Only approved or partially received purchase orders can be received.');
        }

        return DB::transaction(function () use ($order, $quantities, $receiver): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isReceivable()) {
                throw new RuntimeException('Only approved or partially received purchase orders can be received.');
            }

            /** @var EloquentCollection<int, PurchaseOrderItem> $items */
            $items = $locked->items()->with('product')->get();

            $planned = [];

            foreach ($items as $item) {
                $requested = (int) ($quantities[$item->id] ?? 0);

                if ($requested === 0) {
                    continue;
                }

                if ($requested < 0) {
                    throw new RuntimeException('Received quantity cannot be negative.');
                }

                $outstanding = $item->outstandingQuantity();

                if ($requested > $outstanding) {
                    $name = $item->product->name ?? 'line item';

                    throw new RuntimeException("Cannot receive {$requested} units of {$name}: only {$outstanding} unit(s) are outstanding.");
                }

                $planned[$item->id] = $requested;
            }

            if ($planned === []) {
                throw new RuntimeException('Enter at least one quantity to receive.');
            }

            foreach ($items as $item) {
                if (! isset($planned[$item->id])) {
                    continue;
                }

                $quantity = $planned[$item->id];

                $this->inventory->logTransaction(
                    $item->product_id,
                    $receiver->id,
                    'stock_in',
                    $quantity,
                    'Received against purchase order '.$locked->po_number,
                    (float) $item->unit_cost,
                );

                $item->quantity_received += $quantity;
                $item->save();
            }

            $this->syncReceivingStatus($locked);

            $received = $locked->refresh()->load(['items.product', 'supplier', 'creator', 'approver']);

            // Notify the buyer who raised the order. Dispatched inside the
            // transaction so a failed receipt never leaves a stray notification.
            $this->notifications->purchaseOrderReceived($received, array_sum($planned));

            return $received;
        });
    }

    /**
     * Recalculate line totals and the order grand total.
     */
    public function recalculateTotals(PurchaseOrder $order): PurchaseOrder
    {
        $total = 0.0;

        foreach ($order->items()->get() as $item) {
            $lineTotal = round($item->quantity_ordered * (float) $item->unit_cost, 2);

            if ((float) $item->line_total !== $lineTotal) {
                $item->update(['line_total' => $lineTotal]);
            }

            $total += $lineTotal;
        }

        $order->update(['total_amount' => round($total, 2)]);

        return $order;
    }

    /**
     * Purchase order metrics for the dashboard widgets.
     *
     * @return array{open_orders: int, open_value: float, pending_deliveries: int, overdue_deliveries: int, recently_received: EloquentCollection<int, PurchaseOrder>, awaiting_approval: int}
     */
    public function dashboardMetrics(int $recentLimit = 5): array
    {
        $today = Carbon::now()->startOfDay();

        return [
            'open_orders' => PurchaseOrder::whereIn('status', PurchaseOrder::OPEN_STATUSES)->count(),
            'open_value' => round((float) PurchaseOrder::whereIn('status', PurchaseOrder::OPEN_STATUSES)->sum('total_amount'), 2),
            'awaiting_approval' => PurchaseOrder::where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
            'pending_deliveries' => PurchaseOrder::whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES)->count(),
            'overdue_deliveries' => PurchaseOrder::whereIn('status', [
                PurchaseOrder::STATUS_SUBMITTED,
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
                ->whereNotNull('expected_delivery_date')
                ->whereDate('expected_delivery_date', '<', $today)
                ->count(),
            'recently_received' => PurchaseOrder::with(['supplier'])
                ->whereIn('status', [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
                ->whereNotNull('received_at')
                ->orderByDesc('received_at')
                ->limit($recentLimit)
                ->get(),
        ];
    }

    /**
     * Replace the order line items with the supplied set and refresh the totals.
     *
     * @param  list<array{product_id: int, quantity_ordered: int, unit_cost: float}>  $lines
     */
    private function replaceItems(PurchaseOrder $order, array $lines): void
    {
        $keptIds = [];

        foreach ($lines as $line) {
            $lineTotal = round($line['quantity_ordered'] * $line['unit_cost'], 2);

            /** @var PurchaseOrderItem $item */
            $item = $order->items()->updateOrCreate(
                ['product_id' => $line['product_id']],
                [
                    'quantity_ordered' => $line['quantity_ordered'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $lineTotal,
                ],
            );

            $keptIds[] = $item->id;
        }

        $order->items()->whereNotIn('id', $keptIds)->delete();

        $this->recalculateTotals($order);
    }

    /**
     * Validate and de-duplicate the submitted line items.
     *
     * @param  list<array{product_id?: int|string|null, quantity_ordered?: int|string|null, unit_cost?: float|int|string|null}>  $items
     * @return list<array{product_id: int, quantity_ordered: int, unit_cost: float}>
     *
     * @throws RuntimeException
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity_ordered'] ?? 0);
            $unitCost = round((float) ($item['unit_cost'] ?? 0), 2);

            if ($productId <= 0) {
                continue;
            }

            if ($quantity < 1) {
                throw new RuntimeException('Every line item needs a quantity of at least 1.');
            }

            if ($unitCost < 0) {
                throw new RuntimeException('Line item unit cost cannot be negative.');
            }

            if (isset($normalized[$productId])) {
                throw new RuntimeException('The same product cannot be added to a purchase order twice.');
            }

            $normalized[$productId] = [
                'product_id' => $productId,
                'quantity_ordered' => $quantity,
                'unit_cost' => $unitCost,
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('A purchase order needs at least one line item.');
        }

        $missing = array_diff(
            array_keys($normalized),
            Product::whereIn('id', array_keys($normalized))->pluck('id')->all(),
        );

        if ($missing !== []) {
            throw new RuntimeException('One or more selected products no longer exist.');
        }

        return array_values($normalized);
    }

    /**
     * Promote the order to partially received or received based on line progress.
     */
    private function syncReceivingStatus(PurchaseOrder $order): void
    {
        /** @var EloquentCollection<int, PurchaseOrderItem> $items */
        $items = $order->items()->get();

        $fullyReceived = $items->isNotEmpty() && $items->every(fn (PurchaseOrderItem $item): bool => $item->isFullyReceived());

        if ($fullyReceived) {
            $order->update([
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'received_at' => Carbon::now(),
            ]);

            return;
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            'received_at' => Carbon::now(),
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function assertTransition(PurchaseOrder $order, string $status): void
    {
        if ($order->canTransitionTo($status)) {
            return;
        }

        $from = $order->statusLabel();
        $to = PurchaseOrder::STATUSES[$status] ?? $status;

        throw new RuntimeException("A purchase order cannot move from {$from} to {$to}.");
    }

    private function nullableDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
