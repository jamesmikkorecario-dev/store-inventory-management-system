<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\ForecastStockoutNotification;
use App\Notifications\ProductLowStockNotification;
use App\Notifications\PurchaseOrderApprovedNotification;
use App\Notifications\PurchaseOrderCancelledNotification;
use App\Notifications\PurchaseOrderReceivedNotification;
use App\Notifications\PurchaseOrderSubmittedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Target Admin and Staff users (IDs 1, 2, 3, 4) or anyone who is not a supplier
        $users = User::whereIn('id', [1, 2, 3, 4])->get();

        if ($users->isEmpty()) {
            $users = User::take(4)->get();
        }

        $products = Product::take(4)->get();
        $orders = PurchaseOrder::take(4)->get();

        if ($products->isEmpty() || $orders->isEmpty()) {
            $this->command->warn('Please run DatabaseSeeder first to seed products and purchase orders.');

            return;
        }

        $this->command->info('Seeding realistic demo notifications for Admin and Staff users...');

        foreach ($users as $user) {
            // Clear existing notifications for a clean test experience
            $user->notifications()->delete();

            // 1. Critical Low Stock (Unread - 10 minutes ago)
            $user->notify(new ProductLowStockNotification($products[0], 'critical'));

            // 2. Low Stock Warning (Unread - 45 minutes ago)
            if (isset($products[1])) {
                $user->notify(new ProductLowStockNotification($products[1], 'warning'));
            }

            // 3. Forecast Stockout Warning (Unread - 2 hours ago)
            if (isset($products[2])) {
                $user->notify(new ForecastStockoutNotification(
                    $products[2],
                    12,
                    50,
                    30,
                    'warning',
                    Carbon::now()->addDays(12)->toDateString()
                ));
            }

            // 4. Forecast Stockout Critical (Unread - 4 hours ago)
            if (isset($products[3])) {
                $user->notify(new ForecastStockoutNotification(
                    $products[3],
                    4,
                    120,
                    30,
                    'critical',
                    Carbon::now()->addDays(4)->toDateString()
                ));
            }

            // 5. Purchase Order Submitted (Unread - 5 hours ago)
            $user->notify(new PurchaseOrderSubmittedNotification($orders[0]));

            // 6. Purchase Order Approved (Read - 1 day ago)
            if (isset($orders[1])) {
                $user->notify(new PurchaseOrderApprovedNotification($orders[1]));
            }

            // 7. Purchase Order Received (Read - 2 days ago)
            if (isset($orders[2])) {
                $user->notify(new PurchaseOrderReceivedNotification($orders[2], 25));
            }

            // 8. Purchase Order Cancelled (Read - 3 days ago)
            if (isset($orders[3])) {
                $user->notify(new PurchaseOrderCancelledNotification($orders[3]));
            }

            // Stagger creation timestamps and mark the last 3 as read to simulate a realistic inbox
            $notifications = $user->notifications()->orderBy('id', 'desc')->get();
            foreach ($notifications->values() as $index => $notif) {
                $createdAt = Carbon::now()->subHours($index * 3);
                $readAt = $index >= 5 ? Carbon::now()->subHours($index * 2) : null;

                $notif->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'read_at' => $readAt,
                ]);
            }
        }

        $this->command->info('Demo notifications seeded successfully!');
    }
}
