# Store Inventory Management System (SIMS) - System Architecture

This technical architecture document details the design patterns, concurrency safeguards, tenant isolation models, and reactive frontend paradigms governing the Store Inventory Management System (SIMS).

---

## High-Level Architecture

SIMS is built as a monolithic, server-rendered application leveraging **Laravel 13** on PHP 8.5 with **Livewire Volt** functional components for reactive client interactivity without building an external SPA frontend.

```
+-----------------------------------------------------------------------+
|                         Client Browser Layer                          |
|         Tailwind CSS v4  |  Flux UI Components  |  Alpine.js          |
+-----------------------------------------------------------------------+
                                   | (AJAX / Livewire Hydration payloads)
+-----------------------------------------------------------------------+
|                      Livewire 4 / Volt UI Layer                       |
|   Single-File Functional Components (resources/views/pages/*.blade)   |
|   Attribute Security Enforcement (#[Locked], #[Title], #[On])         |
+-----------------------------------------------------------------------+
                                   | (Service Dependency Injection)
+-----------------------------------------------------------------------+
|                    Domain & Application Service Layer                 |
|   InventoryService  |  PurchaseOrderService  |  SupplierPortalService |
+-----------------------------------------------------------------------+
                                   | (Eloquent ORM / DB Transactions)
+-----------------------------------------------------------------------+
|                     Data Persistence & Engine Layer                   |
|   MySQL 8.0+ (InnoDB) | Pessimistic Locking | Spatie Activity Log     |
+-----------------------------------------------------------------------+
```

---

## Concurrency Safeguards & Transaction Integrity

In high-volume warehouse environments, simultaneous operators frequently attempt to receive, allocate, or adjust stock on identical SKUs simultaneously. Without concurrency controls, race conditions result in lost updates and corrupted stock counts.

### 1. Pessimistic Row Locking
All stock mutations are centralized inside `App\Services\InventoryService` and wrapped in database transactions utilizing `lockForUpdate()`:
```php
DB::transaction(function () use ($product, $quantity, $type) {
    // Select and lock the exact product row until transaction commits
    $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();
    
    // Evaluate negative stock boundaries
    if ($type === 'stock_out' && ($lockedProduct->current_stock - $quantity) < 0) {
        throw new InsufficientStockException("Cannot deplete stock below zero.");
    }
    
    // Execute atomic balance update
    $lockedProduct->decrement('current_stock', $quantity);
    
    // Record immutable transaction history log
    InventoryTransaction::create([...]);
});
```
This guarantees serial execution on conflicting SKUs while allowing concurrent transactions on unrelated products to proceed unimpeded.

---

## Role-Based Access Control & Tenant Isolation

SIMS implements a hybrid authorization model combining Spatie RBAC permissions with strict multi-tenant query scoping for external vendors.

### 1. RBAC Hierarchy
- **Admin**: Granted global administrative permissions via `RoleServiceProvider` gate definitions.
- **Staff**: Restricted to operational transaction recording and purchase order drafting.

### 2. Supplier Tenant Scoping
External supplier accounts are assigned a non-null `supplier_id` in the `users` table. All portal queries are passed through `App\Services\SupplierPortalService`, enforcing mandatory tenant binding:
```php
public function getCatalog(Supplier $supplier, string $search = ''): LengthAwarePaginator
{
    return Product::where('supplier_id', $supplier->id)
        ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
        ->paginate(15);
}
```
To prevent ID tampering during Livewire rehydration, all supplier identity properties on public components are decorated with the `#[Locked]` attribute:
```php
#[Locked]
public ?Supplier $supplier = null;
```
If a malicious user modifies `{id: 1}` to `{id: 2}` in their browser payload, Livewire throws a `PropertyLockedException` before executing component actions.

---

## Livewire Volt Functional Architecture

The frontend is architected using Livewire Volt single-file functional components (`resources/views/pages/⚡*.blade.php`), combining PHP state logic and Blade templating in a single file:
- **Event-Driven Reactivity**: Components communicate via Livewire event dispatching (`$this->dispatch('products-updated')`) and listeners (`#[On('products-updated')]`) without page reloads.
- **Optimized SQL Aggregation**: Dashboard valuation metrics avoid loading large Eloquent model collections into PHP memory by utilizing direct SQL expressions:
  ```php
  $this->inventoryValue = (float) Product::selectRaw('COALESCE(SUM(current_stock * cost_price), 0) as total')->value('total');
  ```
- **Flux UI Design System**: Employs curated, accessible Tailwind UI components (`<flux:modal>`, `<flux:table>`, `<flux:badge>`) ensuring uniform spacing and responsive breakpoints across mobile and desktop devices.
