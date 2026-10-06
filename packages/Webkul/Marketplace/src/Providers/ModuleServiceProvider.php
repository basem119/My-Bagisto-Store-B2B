<?php

namespace Webkul\Marketplace\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;
use Webkul\Marketplace\Models\FinancialEntry;
use Webkul\Marketplace\Models\FinancialTransaction;
use Webkul\Marketplace\Models\InvoiceFinancial;
use Webkul\Marketplace\Models\OrderFinancial;
use Webkul\Marketplace\Models\OrderItemFinancial;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Marketplace\Models\VendorStatusHistory;
use Webkul\Marketplace\Models\VendorUser;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    /**
     * Models.
     *
     * @var array
     */
    protected $models = [
        Vendor::class,
        VendorStatusHistory::class,
        VendorUser::class,
        VendorProduct::class,
        FinancialTransaction::class,
        FinancialEntry::class,
        OrderFinancial::class,
        OrderItemFinancial::class,
        InvoiceFinancial::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        parent::register();

        $this->app->register(MarketplaceServiceProvider::class);
    }
}
