<?php

namespace Webkul\Marketplace\Listeners;

use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\DataTypes\FinancialRate;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;
use Webkul\Marketplace\Services\MarketplaceCompanyResolver;
use Webkul\Marketplace\Services\MarketplaceOrderFinancializationService;

class ApplyCompanyPlatformFeeToCart
{
    public function __construct(
        protected MarketplaceCompanyResolver $companyResolver,
    ) {}

    public function handle(CartContract $cart): void
    {
        $items = $cart->items;

        if ($items->isEmpty()) {
            return;
        }

        if (
            $cart->base_currency_code !== FinancialCurrency::EGP->value
            || $cart->cart_currency_code !== FinancialCurrency::EGP->value
        ) {
            throw new MarketplaceFinancializationException('Marketplace checkout supports EGP carts only.');
        }

        $this->companyResolver->resolve($cart->customer, $cart->company_id);

        $grossPrecision = 0;
        $discountPrecision = 0;

        foreach ($items as $item) {
            $grossPrecision += EgpAmount::toPrecisionUnits((string) $item->base_total);
            $discountPrecision += EgpAmount::toPrecisionUnits((string) $item->base_discount_amount);
        }

        if ($discountPrecision > $grossPrecision) {
            throw new MarketplaceFinancializationException('Cart product discounts exceed the product subtotal.');
        }

        $discountedSubtotalPrecision = $grossPrecision - $discountPrecision;
        $discountedSubtotalMinor = EgpAmount::precisionUnitsToMinorUnits($discountedSubtotalPrecision);
        $rate = FinancialRate::normalize(core()->getConfigData('marketplace.financial.company_fee_rate') ?? '0');
        $feeMinor = FinancialRate::percentOfPrecisionUnits($discountedSubtotalPrecision, $rate);

        $snapshotItem = $items->first(fn ($item) => is_array($item->additional['marketplace'] ?? null))
            ?? $items->first();

        foreach ($items as $item) {
            $additional = $item->additional ?? [];
            unset($additional[MarketplaceOrderFinancializationService::CART_SNAPSHOT_KEY]);

            if ($item->id === $snapshotItem->id) {
                $additional[MarketplaceOrderFinancializationService::CART_SNAPSHOT_KEY] = [
                    'company_fee_rate' => $rate,
                    'company_fee_amount_minor' => $feeMinor,
                    'discounted_product_subtotal_minor' => $discountedSubtotalMinor,
                    'discounted_product_subtotal_precision' => $discountedSubtotalPrecision,
                ];
            }

            $item->additional = $additional;
            $item->save();
        }

        $cart->grand_total = EgpAmount::toDatabaseDecimal(
            EgpAmount::toMinorUnits((string) $cart->grand_total) + $feeMinor
        );
        $cart->base_grand_total = EgpAmount::toDatabaseDecimal(
            EgpAmount::toMinorUnits((string) $cart->base_grand_total) + $feeMinor
        );
        $cart->save();
    }
}