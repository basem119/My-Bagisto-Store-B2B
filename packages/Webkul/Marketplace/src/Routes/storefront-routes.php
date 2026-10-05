<?php

use Illuminate\Support\Facades\Route;
use Webkul\Marketplace\Http\Controllers\Shop\MarketplaceController;

/**
 * Public marketplace product discovery — no `customer` middleware (mirrors
 * Bagisto's own public product/category browsing). Distinct from `/vendor`
 * (vendor portal, Phase 6/7) and `/admin/marketplace` (admin oversight).
 */
Route::prefix('marketplace')->middleware(['web', 'theme', 'locale', 'currency'])->group(function () {
    Route::get('', [MarketplaceController::class, 'index'])->name('marketplace.shop.index');

    Route::get('products/{urlKey}', [MarketplaceController::class, 'show'])->name('marketplace.shop.products.show');
});
