<?php

use App\Livewire\Alerts\Index;
use App\Models\Product;
use App\Services\ProductIdentificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('users', 'pages::users')->name('users.index')->middleware('permission:manage users');
    Route::livewire('suppliers', 'pages::suppliers')->name('suppliers.index')->middleware('permission:view suppliers');
    Route::livewire('categories', 'pages::categories')->name('categories.index')->middleware('permission:view categories');
    Route::livewire('products', 'pages::products')->name('products.index')->middleware('role_or_permission:Supplier|view products');

    Route::get('/alerts', Index::class)->name('alerts.index')->middleware('role:Admin|Staff');

    Route::get('/products/{product}/print-label', function (Product $product, Request $request, ProductIdentificationService $service) {
        $type = $request->query('type', 'both');
        $barcodeSvg = $service->generateBarcodeSvg($product->identifier);
        $qrCodeSvg = $service->generateQrCodeSvg($product->identifier);

        return view('pages.products-print-label', compact('product', 'type', 'barcodeSvg', 'qrCodeSvg'));
    })->name('products.print-label')->middleware('role_or_permission:Supplier|view products');

    Route::livewire('transactions', 'pages::transactions')->name('transactions.index')->middleware('role_or_permission:Supplier|view transactions');
    Route::livewire('purchase-orders', 'pages::purchase-orders')->name('purchase-orders.index')->middleware('permission:view purchase orders');
    Route::livewire('purchase-orders/{purchaseOrder}', 'pages::purchase-order')->name('purchase-orders.show')->middleware('permission:view purchase orders');
    Route::livewire('reports', 'pages::reports')->name('reports.index')->middleware('permission:view reports');
    Route::livewire('audit', 'pages::audit')->name('audit.index')->middleware('permission:view audit trail');
});

require __DIR__.'/settings.php';
