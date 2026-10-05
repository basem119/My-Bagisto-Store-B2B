<?php

use Illuminate\Support\Facades\Route;
use Webkul\Marketplace\Http\Controllers\Vendor\DashboardController;
use Webkul\Marketplace\Http\Controllers\Vendor\OnboardingController;
use Webkul\Marketplace\Http\Controllers\Vendor\OrderController;
use Webkul\Marketplace\Http\Controllers\Vendor\ProductController;
use Webkul\Marketplace\Http\Controllers\Vendor\ProfileController;
use Webkul\Marketplace\Http\Controllers\Vendor\TeamController;
use Webkul\Marketplace\Http\Middleware\EnsureVendorContext;

/**
 * Vendor Portal — storefront-customer-guarded, distinct from /admin and the
 * regular /customer account area. Every route except the application form
 * itself requires an active membership in the specific {vendor} (enforced
 * by EnsureVendorContext, never trusted from the URL alone).
 */
Route::prefix('vendor')->middleware(['web', 'theme', 'locale', 'currency', 'customer'])->group(function () {
    Route::controller(OnboardingController::class)->prefix('apply')->group(function () {
        Route::get('', 'create')->name('marketplace.vendor.apply.create');
        Route::post('', 'store')->name('marketplace.vendor.apply.store');
    });

    Route::middleware(EnsureVendorContext::class)->prefix('{vendor:slug}')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('marketplace.vendor.dashboard');

        Route::controller(ProfileController::class)->prefix('profile')->group(function () {
            Route::get('', 'edit')->name('marketplace.vendor.profile.edit');
            Route::put('', 'update')->name('marketplace.vendor.profile.update');
        });

        Route::controller(TeamController::class)->prefix('team')->group(function () {
            Route::get('', 'index')->name('marketplace.vendor.team.index');
            Route::post('', 'store')->name('marketplace.vendor.team.store');
            Route::put('{customer}', 'update')->name('marketplace.vendor.team.update');
            Route::delete('{customer}', 'destroy')->name('marketplace.vendor.team.destroy');
        });

        Route::controller(ProductController::class)->prefix('products')->group(function () {
            Route::get('', 'index')->name('marketplace.vendor.products.index');
            Route::get('create', 'create')->name('marketplace.vendor.products.create');
            Route::post('', 'store')->name('marketplace.vendor.products.store');
            Route::get('{vendorProduct}/edit', 'edit')->name('marketplace.vendor.products.edit');
            Route::put('{vendorProduct}', 'update')->name('marketplace.vendor.products.update');
            Route::delete('{vendorProduct}', 'destroy')->name('marketplace.vendor.products.destroy');
        });

        Route::controller(OrderController::class)->prefix('orders')->group(function () {
            Route::get('', 'index')->name('marketplace.vendor.orders.index');
            Route::get('{orderId}', 'show')->name('marketplace.vendor.orders.show');
        });
    });
});
