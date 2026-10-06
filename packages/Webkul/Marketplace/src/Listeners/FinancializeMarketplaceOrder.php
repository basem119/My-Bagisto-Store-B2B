<?php

namespace Webkul\Marketplace\Listeners;

use Webkul\Sales\Contracts\Order as OrderContract;
use Webkul\Marketplace\Services\MarketplaceOrderFinancializationService;

class FinancializeMarketplaceOrder
{
    public function __construct(
        protected MarketplaceOrderFinancializationService $financializationService,
    ) {}

    public function handle(OrderContract $order): void
    {
        $this->financializationService->financialize($order);
    }
}