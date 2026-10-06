<?php

use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;
use Webkul\Marketplace\Http\Controllers\Admin\VendorController;
use Webkul\Marketplace\Http\Controllers\Admin\VendorOrderController;
use Webkul\Marketplace\Http\Controllers\Admin\VendorProductController;

/**
 * Platform-admin vendor lifecycle management — protected by Bagisto's
 * existing admin auth + ACL (Bouncer), same pattern as every other admin
 * route in the application (see B2B Suite's admin-routes.php precedent).
 */
Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url').'/marketplace'], function () {
    Route::controller(VendorController::class)->prefix('vendors')->group(function () {
        Route::get('', 'index')->name('marketplace.admin.vendors.index');

        Route::get('{vendor}', 'view')->name('marketplace.admin.vendors.view');

        Route::post('{vendor}/approve', 'approve')->name('marketplace.admin.vendors.approve');

        Route::post('{vendor}/reject', 'reject')->name('marketplace.admin.vendors.reject');

        Route::post('{vendor}/suspend', 'suspend')->name('marketplace.admin.vendors.suspend');

        Route::post('{vendor}/reactivate', 'reactivate')->name('marketplace.admin.vendors.reactivate');

        Route::post('{vendor}/commission', 'updateCommissionRate')->name('marketplace.admin.vendors.commission');
    });

    Route::get('vendor-products', [VendorProductController::class, 'index'])->name('marketplace.admin.vendor-products.index');

    Route::get('vendor-orders', [VendorOrderController::class, 'index'])->name('marketplace.admin.vendor-orders.index');
});
