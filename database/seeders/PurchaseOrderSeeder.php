<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a representative purchase order pipeline: one order per workflow status,
 * plus an overdue delivery so the dashboard and report widgets have data.
 *
 * Every order is created through {@see PurchaseOrderService} rather than by
 * inserting rows directly, so PO numbering, line totals, status timestamps and
 * the stock in transactions raised by receiving all stay internally consistent.
 */
class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(PurchaseOrderService::class);

        $buyer = User::whereHas('roles', fn ($q) => $q->where('name', 'Staff'))->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->firstOrFail();

        $approver = User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->firstOrFail();

        $suppliers = Supplier::where('status', 'active')
            ->whereHas('products', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->get();

        if ($suppliers->isEmpty()) {
            return;
        }

        // Draft — still being prepared.
        $this->makeOrder($service, $suppliers, 0, $buyer, 10, 'Quarterly replenishment, awaiting budget sign-off.');

        // Submitted — waiting on an approver.
        $submitted = $this->makeOrder($service, $suppliers, 1, $buyer, 7, 'Submitted for procurement approval.');
        $service->submit($submitted);

        // Approved — approved but nothing delivered yet.
        $approved = $this->makeOrder($service, $suppliers, 2, $buyer, 5, 'Approved; delivery scheduled with the supplier.');
        $service->approve($service->submit($approved), $approver);

        // Partially received — one line booked in short.
        $partial = $this->makeOrder($service, $suppliers, 3, $buyer, 3, 'Partial delivery received; backorder outstanding.');
        $service->approve($service->submit($partial), $approver);
        $partial->load('items');
        $firstItem = $partial->items->first();

        if ($firstItem !== null) {
            $service->receive($partial, [$firstItem->id => max(1, (int) floor($firstItem->quantity_ordered / 2))], $buyer);
        }

        // Received — fully delivered and closed out.
        $received = $this->makeOrder($service, $suppliers, 4, $buyer, -4, 'Delivered in full and reconciled against the invoice.');
        $service->approve($service->submit($received), $approver);
        $received->load('items');
        $service->receive(
            $received,
            $received->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
            $buyer,
        );

        // Cancelled — supplier could not fulfil.
        $cancelled = $this->makeOrder($service, $suppliers, 5, $buyer, 6, 'Raised against a discontinued product line.');
        $service->cancel($cancelled, 'Supplier discontinued the requested items.');

        // Overdue — approved, expected date already passed.
        $overdue = $this->makeOrder($service, $suppliers, 6, $buyer, -6, 'Chasing the supplier; delivery is past due.');
        $service->approve($service->submit($overdue), $approver);
    }

    /**
     * Build a purchase order for the supplier at the given offset in the list.
     *
     * @param  Collection<int, Supplier>  $suppliers
     * @param  int  $deliveryOffsetDays  days from today for the expected delivery (negative = overdue)
     */
    private function makeOrder(
        PurchaseOrderService $service,
        Collection $suppliers,
        int $index,
        User $buyer,
        int $deliveryOffsetDays,
        string $notes,
    ): PurchaseOrder {
        /** @var Supplier $supplier */
        $supplier = $suppliers[$index % $suppliers->count()];

        $products = Product::where('supplier_id', $supplier->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(3)
            ->get();

        /** @var list<array{product_id: int, quantity_ordered: int, unit_cost: float}> $items */
        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'product_id' => $product->id,
                'quantity_ordered' => fake()->numberBetween(10, 60),
                'unit_cost' => round((float) $product->cost_price * fake()->randomFloat(2, 0.9, 1.05), 2),
            ];
        }

        $orderDate = Carbon::now()->subDays(fake()->numberBetween(2, 25));

        return $service->create([
            'supplier_id' => $supplier->id,
            'order_date' => $orderDate->toDateString(),
            'expected_delivery_date' => Carbon::now()->addDays($deliveryOffsetDays)->toDateString(),
            'notes' => $notes,
        ], $items, $buyer);
    }
}
