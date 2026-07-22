<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('users', 'pages::users')->name('users.index');
    Route::livewire('suppliers', 'pages::suppliers')->name('suppliers.index');
    Route::livewire('categories', 'pages::categories')->name('categories.index');
    Route::livewire('products', 'pages::products')->name('products.index');
    Route::livewire('transactions', 'pages::transactions')->name('transactions.index');
    Route::livewire('reports', 'pages::reports')->name('reports.index');
    Route::livewire('audit', 'pages::audit')->name('audit.index');
});

require __DIR__.'/settings.php';
