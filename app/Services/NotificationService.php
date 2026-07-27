<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\ForecastStockoutNotification;
use App\Notifications\ProductLowStockNotification;
use App\Notifications\PurchaseOrderApprovedNotification;
use App\Notifications\PurchaseOrderCancelledNotification;
use App\Notifications\PurchaseOrderReceivedNotification;
use App\Notifications\PurchaseOrderSubmittedNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Single entry point for creating and reading database notifications.
 *
 * All dispatching lives here so recipient resolution and duplicate suppression
 * are defined once rather than at each call site. Notifications are stored on the
 * database channel only; no mail is sent.
 *
 * Performance notes:
 *  - Recipient lookups use one `role` scoped / `permission` scoped query and send
 *    via {@see NotificationFacade::send()}, which performs a single insert per
 *    recipient rather than per-recipient model hydration.
 *  - Duplicate checks are a single indexed `COUNT` against `notifications`,
 *    filtered by type and a JSON path on `data`, so they do not grow with history.
 *  - {@see summaryFor()} answers every dashboard widget with a handful of
 *    aggregate `COUNT` queries and no row hydration.
 */
class NotificationService
{
    /**
     * Roles that receive inventory and purchase order notifications.
     *
     * @var list<string>
     */
    public const INTERNAL_ROLES = ['Admin', 'Staff'];

    public function __construct(private readonly ForecastService $forecasts) {}

    // ─── Low stock ────────────────────────────────────────────────────────────

    /**
     * Notify internal staff that a product has hit its minimum stock level.
     *
     * Returns the number of recipients notified; zero when an unread notification
     * for the same product already exists.
     */
    public function lowStock(Product $product): int
    {
        if ($product->current_stock > $product->minimum_stock) {
            return 0;
        }

        if ($this->hasUnreadFor(ProductLowStockNotification::class, 'product_id', $product->id)) {
            return 0;
        }

        $severity = $product->current_stock <= 0 ? 'critical' : 'warning';

        return $this->sendToInternalUsers(new ProductLowStockNotification($product, $severity));
    }

    // ─── Forecast stockouts ───────────────────────────────────────────────────

    /**
     * Notify internal staff that a product is forecast to deplete soon.
     */
    public function forecastStockout(Product $product, ?int $periodDays = null): int
    {
        $days = $this->forecasts->periodDays($periodDays);
        $forecast = $this->forecasts->forecastFor($product, $days);

        if ($forecast['days_remaining'] === null || $forecast['days_remaining'] > ForecastService::WARNING_DAYS) {
            return 0;
        }

        if ($this->hasUnreadFor(ForecastStockoutNotification::class, 'product_id', $product->id)) {
            return 0;
        }

        $daysRemaining = (int) floor($forecast['days_remaining']);
        $severity = $daysRemaining <= ForecastService::CRITICAL_DAYS ? 'critical' : 'warning';

        return $this->sendToInternalUsers(new ForecastStockoutNotification(
            $product,
            $daysRemaining,
            $forecast['suggested_reorder_quantity'],
            $days,
            $severity,
            $forecast['stockout_date']?->toDateString(),
        ));
    }

    /**
     * Scan the catalogue and raise forecast notifications for at-risk products.
     *
     * Uses the forecast query's single aggregate join, so the scan costs one query
     * for the candidate set plus one duplicate check per candidate.
     *
     * @return array{scanned: int, notified: int}
     */
    public function dispatchForecastStockouts(?int $periodDays = null): array
    {
        $days = $this->forecasts->periodDays($periodDays);

        $candidates = $this->forecasts->forecastQuery(new ReportFilters(forecastDays: $days))
            ->whereRaw('COALESCE(usage.usage_units, 0) > 0')
            ->get();

        $notified = 0;

        foreach ($candidates as $product) {
            if ($this->forecastStockout($product, $days) > 0) {
                $notified++;
            }
        }

        return ['scanned' => $candidates->count(), 'notified' => $notified];
    }

    // ─── Purchase order workflow ──────────────────────────────────────────────

    /**
     * Notify approvers that an order is waiting on them.
     */
    public function purchaseOrderSubmitted(PurchaseOrder $order): int
    {
        $approvers = User::permission('approve purchase orders')->get();

        if ($approvers->isEmpty()) {
            return 0;
        }

        NotificationFacade::send($approvers, new PurchaseOrderSubmittedNotification($order->loadMissing('supplier')));

        return $approvers->count();
    }

    /**
     * Notify the order's creator that it was approved.
     */
    public function purchaseOrderApproved(PurchaseOrder $order): int
    {
        return $this->notifyCreator($order, fn (PurchaseOrder $o) => new PurchaseOrderApprovedNotification($o));
    }

    /**
     * Notify the order's creator that goods were booked in.
     */
    public function purchaseOrderReceived(PurchaseOrder $order, int $unitsReceived = 0): int
    {
        return $this->notifyCreator($order, fn (PurchaseOrder $o) => new PurchaseOrderReceivedNotification($o, $unitsReceived));
    }

    /**
     * Notify the order's creator that it was cancelled.
     */
    public function purchaseOrderCancelled(PurchaseOrder $order): int
    {
        return $this->notifyCreator($order, fn (PurchaseOrder $o) => new PurchaseOrderCancelledNotification($o));
    }

    // ─── Reading ──────────────────────────────────────────────────────────────

    /**
     * Unread notification count for a user.
     */
    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * The user's most recent notifications, newest first.
     *
     * @return EloquentCollection<int, DatabaseNotification>
     */
    public function recentFor(User $user, int $limit = 8, bool $unreadOnly = false): EloquentCollection
    {
        $query = $user->notifications()->getQuery();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        /** @var EloquentCollection<int, DatabaseNotification> $rows */
        $rows = $query->limit($limit)->get();

        return $rows;
    }

    /**
     * Mark one of the user's own notifications as read.
     *
     * Scoped through the notifiable relation, so a user can never mark another
     * user's notification as read even with a guessed id.
     */
    public function markAsRead(User $user, string $notificationId): bool
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->whereKey($notificationId)->first();

        if ($notification === null || $notification->read_at !== null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    /**
     * Mark every unread notification for the user as read.
     */
    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * Aggregate counts backing the dashboard notification widgets.
     *
     * Each figure is a `COUNT` with no row hydration. Purchase order figures are
     * omitted (returned as null) for users without the relevant permission so the
     * widgets stay both role and permission aware.
     *
     * @return array{unread: int, critical_low_stock: int, forecast_stockouts: int|null, pending_approvals: int|null, overdue_orders: int|null}
     */
    public function summaryFor(User $user): array
    {
        $canSeeForecasts = $user->can('view forecasts');
        $canSeeOrders = $user->can('view purchase orders');
        $canApprove = $user->can('approve purchase orders');

        return [
            'unread' => $this->unreadCountFor($user),
            'critical_low_stock' => $user->unreadNotifications()
                ->where('type', ProductLowStockNotification::class)
                ->where('data->severity', 'critical')
                ->count(),
            'forecast_stockouts' => $canSeeForecasts
                ? $user->unreadNotifications()->where('type', ForecastStockoutNotification::class)->count()
                : null,
            'pending_approvals' => $canApprove
                ? PurchaseOrder::where('status', PurchaseOrder::STATUS_SUBMITTED)->count()
                : null,
            'overdue_orders' => $canSeeOrders
                ? PurchaseOrder::whereIn('status', [
                    PurchaseOrder::STATUS_SUBMITTED,
                    PurchaseOrder::STATUS_APPROVED,
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                ])
                    ->whereNotNull('expected_delivery_date')
                    ->whereDate('expected_delivery_date', '<', now()->startOfDay())
                    ->count()
                : null,
        ];
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Whether any unread notification of the given type already references the value.
     *
     * This is what stops a product from producing a second notification while the
     * first is still unread; a new one is only possible once the previous is read.
     */
    private function hasUnreadFor(string $notificationClass, string $dataKey, int|string $value): bool
    {
        return DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', $notificationClass)
            ->where('data->'.$dataKey, $value)
            ->exists();
    }

    /**
     * Send a notification to every Admin and Staff user.
     */
    private function sendToInternalUsers(object $notification): int
    {
        try {
            $recipients = User::role(self::INTERNAL_ROLES)->get();
        } catch (RoleDoesNotExist $e) {
            return 0;
        }

        if ($recipients->isEmpty()) {
            return 0;
        }

        NotificationFacade::send($recipients, $notification);

        return $recipients->count();
    }

    /**
     * Send a notification to the user who raised the order, if they still exist.
     *
     * @param  callable(PurchaseOrder): object  $factory
     */
    private function notifyCreator(PurchaseOrder $order, callable $factory): int
    {
        $order->loadMissing(['creator', 'supplier']);

        $creator = $order->creator;

        if ($creator === null) {
            return 0;
        }

        $creator->notify($factory($order));

        return 1;
    }
}
