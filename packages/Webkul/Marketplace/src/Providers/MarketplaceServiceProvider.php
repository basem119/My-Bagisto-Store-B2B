<?php

namespace Webkul\Marketplace\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Marketplace\Http\Middleware\EnsureVendorContext;
use Webkul\Marketplace\Listeners\AllocateCompanyFeeToInvoice;
use Webkul\Marketplace\Listeners\ApplyCompanyPlatformFeeToCart;
use Webkul\Marketplace\Listeners\CreateMarketplacePaymentPlan;
use Webkul\Marketplace\Listeners\FinancializeMarketplaceOrder;
use Webkul\Marketplace\Listeners\RevalidateVendorOfferQuantity;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Policies\VendorPolicy;
use Webkul\Marketplace\Type\MarketplaceAwareSimple;
use Webkul\Product\Type\Simple;

/**
 * Registers the vendor-scoped authorization boundary, routes, views and ACL.
 * Kept separate from ModuleServiceProvider (which Concord uses purely for
 * migrations/models), same pattern as Paymob's ModuleServiceProvider ->
 * PaymobServiceProvider split. Routes are loaded manually here (not via
 * Concord's route auto-loading) for consistency with B2BSuiteServiceProvider's
 * own manual `Route::middleware('web')->group(...)` precedent.
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/admin/acl.php', 'acl');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/system.php', 'core');

        /**
         * Same container-rebinding pattern B2B Suite itself uses for
         * ProductRepository/Customer (see B2BSuiteManager) — never edits
         * Webkul\Product\Type\Simple. Only affects cart-item identity/price
         * when `vendor_product_id` is present in the submitted data (see
         * MarketplaceAwareSimple); every other Simple product is unaffected.
         */
        $this->app->bind(Simple::class, MarketplaceAwareSimple::class);
    }

    public function boot(): void
    {
        Gate::policy(Vendor::class, VendorPolicy::class);

        $this->app['router']->aliasMiddleware('vendor.context', EnsureVendorContext::class);

        Route::middleware('web')->group(dirname(__DIR__).'/Routes/web.php');

        $this->loadViewsFrom(dirname(__DIR__).'/Resources/views', 'marketplace');

        $this->loadTranslationsFrom(dirname(__DIR__).'/Resources/lang', 'marketplace');

        Event::listen('checkout.cart.update.before', RevalidateVendorOfferQuantity::class);
        Event::listen('checkout.cart.collect.totals.after', ApplyCompanyPlatformFeeToCart::class);
        Event::listen('checkout.order.save.after', FinancializeMarketplaceOrder::class);
        Event::listen('checkout.order.save.after', CreateMarketplacePaymentPlan::class);
        Event::listen('sales.invoice.save.after', AllocateCompanyFeeToInvoice::class);

        /**
         * Read-only marketplace panel on the existing admin order-item
         * display (Bagisto's own extension hook — no core Blade file is
         * touched). No-op for any order item without marketplace metadata.
         */
        Event::listen('bagisto.admin.sales.order.list.item.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('marketplace::admin.order-items.marketplace-info');
        });

        Event::listen('bagisto.shop.checkout.cart.summary.grand_total.before', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('marketplace::shop.checkout.company-fee');
        });

        Event::listen('bagisto.shop.checkout.onepage.summary.grand_total.before', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('marketplace::shop.checkout.company-fee');
        });
    }
}

