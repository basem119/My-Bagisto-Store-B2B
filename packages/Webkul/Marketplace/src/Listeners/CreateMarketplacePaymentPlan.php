<?php

namespace Webkul\Marketplace\Listeners;

use Webkul\Marketplace\Services\MarketplaceCompanyResolver;
use Webkul\Marketplace\Services\MarketplacePaymentPlanService;
use Webkul\Sales\Contracts\Order as OrderContract;

/**
 * Creates the Phase 12C payment plan for credit-funded ("paybycredit")
 * orders, immediately after Phase 12B's financialization listener runs for
 * the same 'checkout.order.save.after' event (registered after it in
 * MarketplaceServiceProvider::boot()). No-op for any order not paid by
 * company credit.
 */
class CreateMarketplacePaymentPlan
{
    public function __construct(
        protected MarketplaceCompanyResolver $companyResolver,
        protected MarketplacePaymentPlanService $paymentPlanService,
    ) {}

    public function handle(OrderContract $order): void
    {
        if ($order->payment?->method !== 'paybycredit') {
            return;
        }

        $company = $this->companyResolver->resolve($order->customer, $order->cart?->company_id);

        $this->paymentPlanService->createForOrder($order, $company);
    }
}
