<?php

use App\Livewire\Alerts\Index;
use App\Models\Product;
use App\Services\ProductIdentificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('users', 'pages::users')->name('users.index');
    Route::livewire('suppliers', 'pages::suppliers')->name('suppliers.index');
    Route::livewire('categories', 'pages::categories')->name('categories.index');
    Route::livewire('products', 'pages::products')->name('products.index');

    Route::get('/alerts', Index::class)->name('alerts.index');

    Route::get('/products/{product}/print-label', function (Product $product, Request $request, ProductIdentificationService $service) {
        $type = $request->query('type', 'both');
        $barcodeSvg = $service->generateBarcodeSvg($product->identifier);
        $qrCodeSvg = $service->generateQrCodeSvg($product->identifier);

        return view('pages.products-print-label', compact('product', 'type', 'barcodeSvg', 'qrCodeSvg'));
    })->name('products.print-label');

    Route::livewire('transactions', 'pages::transactions')->name('transactions.index');
    Route::livewire('reports', 'pages::reports')->name('reports.index');
    Route::livewire('audit', 'pages::audit')->name('audit.index');
});

require __DIR__.'/settings.php';
