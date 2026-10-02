<?php

namespace Webkul\Marketplace\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Policies\VendorPolicy;

/**
 * Registers the vendor-scoped authorization boundary. Kept separate from
 * ModuleServiceProvider (which Concord uses purely for migrations/models), same
 * pattern as Paymob's ModuleServiceProvider -> PaymobServiceProvider split.
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Vendor::class, VendorPolicy::class);
    }
}
