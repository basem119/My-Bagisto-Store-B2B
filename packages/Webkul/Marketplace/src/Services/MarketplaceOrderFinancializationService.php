<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\DataTypes\FinancialRate;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;
use Webkul\Marketplace\Models\OrderFinancial;
use Webkul\Marketplace\Models\OrderItemFinancial;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Sales\Contracts\Order as OrderContract;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;

class MarketplaceOrderFinancializationService
{
    public const CART_SNAPSHOT_KEY = 'marketplace_financial';

    public function __construct(
        protected MarketplaceCompanyResolver $companyResolver,
    ) {}

    public function financialize(OrderContract $order): ?OrderFinancial
    {
        if (! $order instanceof Order) {
            throw new MarketplaceFinancializationException('The order implementation is not supported.');
        }

        $order->loadMissing(['items', 'customer', 'cart']);

        $marketplaceItems = $order->items
            ->filter(fn (OrderItem $item) => is_array($item->additional['marketplace'] ?? null))
            ->values();

        $this->assertEgpOrder($order);
        $company = $this->companyResolver->resolve($order->customer, $order->cart?->company_id);
        $itemSnapshots = $this->buildItemSnapshots($marketplaceItems);
        $productTotals = $this->productTotals($order->items);
        $feeSnapshot = $this->feeSnapshot($order->items->all(), $productTotals['discounted_precision']);
        $totals = $this->orderTotals($order, $productTotals, $feeSnapshot['fee_minor']);

        return DB::transaction(function () use ($order, $company, $itemSnapshots, $productTotals, $feeSnapshot, $totals) {
            Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $existing = OrderFinancial::query()
                ->with('items')
                ->where('order_id', $order->id)
                ->first();

            if ($existing) {
                $this->assertExistingSnapshotMatches($existing, $order, $company->id, $itemSnapshots, $productTotals, $feeSnapshot, $totals);

                return $existing;
            }

            $header = new OrderFinancial;
            $header->forceFill([
                'order_id' => $order->id,
                'company_id' => $company->id,
                'currency' => FinancialCurrency::EGP->value,
                'product_subtotal' => EgpAmount::toDatabasePrecisionDecimal($productTotals['gross_precision']),
                'product_discount_amount' => EgpAmount::toDatabasePrecisionDecimal($productTotals['discount_precision']),
                'discounted_product_subtotal' => EgpAmount::toDatabasePrecisionDecimal($productTotals['discounted_precision']),
                'company_fee_rate' => $feeSnapshot['rate'],
                'company_fee_amount' => EgpAmount::toDatabaseDecimal($feeSnapshot['fee_minor']),
                'delivery_amount' => EgpAmount::toDatabaseDecimal($totals['delivery_minor']),
                'delivery_discount_amount' => EgpAmount::toDatabaseDecimal($totals['delivery_discount_minor']),
                'product_tax_amount' => EgpAmount::toDatabaseDecimal($totals['product_tax_minor']),
                'delivery_tax_amount' => EgpAmount::toDatabaseDecimal($totals['delivery_tax_minor']),
                'tax_amount' => EgpAmount::toDatabaseDecimal($totals['tax_minor']),
                'customer_financial_total' => EgpAmount::toDatabaseDecimal($totals['customer_total_minor']),
            ])->save();

            foreach ($itemSnapshots as $snapshot) {
                $itemFinancial = new OrderItemFinancial;
                $itemFinancial->forceFill([
                    'order_financial_id' => $header->id,
                    ...$snapshot,
                ])->save();
            }

            return $header->load('items');
        });
    }

    private function buildItemSnapshots($items): array
    {
        $snapshots = [];

        foreach ($items as $item) {
            $marketplace = $item->additional['marketplace'];
            $vendorId = $this->requiredPositiveId($marketplace['vendor_id'] ?? null, 'vendor_id');
            $vendorProductId = $this->requiredPositiveId($marketplace['vendor_product_id'] ?? null, 'vendor_product_id');
            $vendorName = $marketplace['vendor_name'] ?? null;
            $vendorSku = $marketplace['vendor_sku'] ?? null;

            if (! is_string($vendorName) || $vendorName === '') {
                throw new MarketplaceFinancializationException("Marketplace order item #{$item->id} is missing its historical vendor name.");
            }

            if ($vendorSku !== null && ! is_string($vendorSku)) {
                throw new MarketplaceFinancializationException("Marketplace order item #{$item->id} has an invalid historical vendor SKU.");
            }

            $vendor = Vendor::query()->find($vendorId);

            if (! $vendor) {
                throw new MarketplaceFinancializationException("Marketplace vendor #{$vendorId} no longer exists for order item #{$item->id}.");
            }

            $basisPrecision = $this->discountedLineBasis($item);
            $rate = FinancialRate::normalize($vendor->commission_rate ?? core()->getConfigData('marketplace.financial.default_vendor_commission_rate') ?? '0');
            $basisMinor = EgpAmount::precisionUnitsToMinorUnits($basisPrecision);
            $commissionMinor = FinancialRate::percentOfPrecisionUnits($basisPrecision, $rate);

            if ($commissionMinor * 100 > $basisPrecision) {
                throw new MarketplaceFinancializationException("Commission exceeds discounted value for order item #{$item->id}.");
            }

            $snapshots[] = [
                'order_item_id' => $item->id,
                'vendor_id' => $vendorId,
                'vendor_product_id' => $vendorProductId,
                'product_id' => $item->product_id,
                'vendor_name' => $vendorName,
                'vendor_sku' => $vendorSku,
                'quantity' => (string) $item->qty_ordered,
                'vendor_price' => EgpAmount::toDatabaseDecimal(EgpAmount::toMinorUnits((string) $item->base_price)),
                'discounted_product_basis' => EgpAmount::toDatabasePrecisionDecimal($basisPrecision),
                'commission_rate' => $rate,
                'commission_amount' => EgpAmount::toDatabaseDecimal($commissionMinor),
                'vendor_net_amount' => EgpAmount::toDatabasePrecisionDecimal($basisPrecision - ($commissionMinor * 100)),
                'currency' => FinancialCurrency::EGP->value,
            ];
        }

        return $snapshots;
    }

    private function productTotals($items): array
    {
        $gross = 0;
        $discount = 0;

        foreach ($items as $item) {
            $gross += EgpAmount::toPrecisionUnits((string) $item->base_total);
            $discount += EgpAmount::toPrecisionUnits((string) $item->base_discount_amount);
        }

        if ($discount > $gross) {
            throw new MarketplaceFinancializationException('Order product discounts exceed the product subtotal.');
        }

        return [
            'gross_precision' => $gross,
            'discount_precision' => $discount,
            'discounted_precision' => $gross - $discount,
            'gross_minor' => EgpAmount::precisionUnitsToMinorUnits($gross),
            'discounted_minor' => EgpAmount::precisionUnitsToMinorUnits($gross - $discount),
        ];
    }

    private function feeSnapshot(array $items, int $discountedSubtotalPrecision): array
    {
        $snapshots = [];

        foreach ($items as $item) {
            $snapshot = $item->additional[self::CART_SNAPSHOT_KEY] ?? null;

            if (is_array($snapshot)) {
                $snapshots[] = $snapshot;
            }
        }

        if ($snapshots === []) {
            throw new MarketplaceFinancializationException('The cart did not preserve the company fee snapshot used in its payable total.');
        }

        $first = $snapshots[0];
        $rate = FinancialRate::normalize($first['company_fee_rate'] ?? null);
        $feeMinor = FinancialRate::percentOfPrecisionUnits($discountedSubtotalPrecision, $rate);

        foreach ($snapshots as $snapshot) {
            if (
                FinancialRate::normalize($snapshot['company_fee_rate'] ?? null) !== $rate
                || (int) ($snapshot['discounted_product_subtotal_precision'] ?? -1) !== $discountedSubtotalPrecision
                || (int) ($snapshot['company_fee_amount_minor'] ?? -1) !== $feeMinor
            ) {
                throw new MarketplaceFinancializationException('Marketplace cart fee snapshots are inconsistent with the final order items.');
            }
        }

        return ['rate' => $rate, 'fee_minor' => $feeMinor];
    }

    private function orderTotals(Order $order, array $productTotals, int $feeMinor): array
    {
        $delivery = EgpAmount::toMinorUnits((string) $order->base_shipping_amount);
        $deliveryDiscount = EgpAmount::toMinorUnits((string) $order->base_shipping_discount_amount);
        $productTax = 0;

        foreach ($order->items as $item) {
            $productTax += EgpAmount::toMinorUnits((string) $item->base_tax_amount);
        }

        $deliveryTax = EgpAmount::toMinorUnits((string) $order->base_shipping_tax_amount);
        $tax = EgpAmount::toMinorUnits((string) $order->base_tax_amount);
        $customerTotal = EgpAmount::toMinorUnits((string) $order->base_grand_total);

        if ($deliveryDiscount > $delivery) {
            throw new MarketplaceFinancializationException('The Bagisto shipping discount exceeds its shipping amount.');
        }

        $reconciled = $productTotals['discounted_minor'] + $feeMinor + ($delivery - $deliveryDiscount) + $tax;

        if ($reconciled !== $customerTotal || $productTax + $deliveryTax !== $tax) {
            throw new MarketplaceFinancializationException('Marketplace financial components do not reconcile with the canonical Bagisto order total.');
        }

        return [
            'delivery_minor' => $delivery,
            'delivery_discount_minor' => $deliveryDiscount,
            'product_tax_minor' => $productTax,
            'delivery_tax_minor' => $deliveryTax,
            'tax_minor' => $tax,
            'customer_total_minor' => $customerTotal,
        ];
    }

    private function assertEgpOrder(Order $order): void
    {
        if ($order->base_currency_code !== FinancialCurrency::EGP->value || $order->order_currency_code !== FinancialCurrency::EGP->value) {
            throw new MarketplaceFinancializationException('Marketplace financialization supports EGP orders only.');
        }
    }

    private function discountedLineBasis(OrderItem $item): int
    {
        $total = EgpAmount::toPrecisionUnits((string) $item->base_total);
        $discount = EgpAmount::toPrecisionUnits((string) $item->base_discount_amount);

        if ($discount > $total) {
            throw new MarketplaceFinancializationException("Order item #{$item->id} discounts exceed its total.");
        }

        return $total - $discount;
    }

    private function requiredPositiveId(mixed $value, string $key): int
    {
        if (! is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new MarketplaceFinancializationException("Marketplace order metadata is missing a valid {$key}.");
        }

        $id = (int) $value;

        if ($id < 1) {
            throw new MarketplaceFinancializationException("Marketplace order metadata is missing a valid {$key}.");
        }

        return $id;
    }

    private function assertExistingSnapshotMatches(
        OrderFinancial $existing,
        Order $order,
        int $companyId,
        array $itemSnapshots,
        array $productTotals,
        array $feeSnapshot,
        array $totals,
    ): void {
        $expectedItems = collect($itemSnapshots)->keyBy('order_item_id');
        $existingItems = $existing->items->keyBy('order_item_id');

        $headerMatches = (int) $existing->company_id === $companyId
            && EgpAmount::toPrecisionUnits((string) $existing->discounted_product_subtotal) === $productTotals['discounted_precision']
            && FinancialRate::normalize((string) $existing->company_fee_rate) === $feeSnapshot['rate']
            && EgpAmount::toMinorUnits((string) $existing->company_fee_amount) === $feeSnapshot['fee_minor']
            && EgpAmount::toMinorUnits((string) $existing->delivery_amount) === $totals['delivery_minor']
            && EgpAmount::toMinorUnits((string) $existing->delivery_discount_amount) === $totals['delivery_discount_minor']
            && EgpAmount::toMinorUnits((string) $existing->tax_amount) === $totals['tax_minor']
            && EgpAmount::toMinorUnits((string) $existing->customer_financial_total) === $totals['customer_total_minor'];

        if (! $headerMatches || $expectedItems->keys()->sort()->values()->all() !== $existingItems->keys()->sort()->values()->all()) {
            throw new MarketplaceFinancializationException("Existing financialization for order #{$order->id} conflicts with the canonical order snapshot.");
        }

        foreach ($expectedItems as $orderItemId => $expected) {
            $actual = $existingItems->get($orderItemId);

            if (
                (int) $actual->vendor_id !== $expected['vendor_id']
                || (int) $actual->vendor_product_id !== $expected['vendor_product_id']
                || $actual->vendor_name !== $expected['vendor_name']
                || $actual->vendor_sku !== $expected['vendor_sku']
                || EgpAmount::toPrecisionUnits((string) $actual->discounted_product_basis) !== EgpAmount::toPrecisionUnits($expected['discounted_product_basis'])
            ) {
                throw new MarketplaceFinancializationException("Existing financial item snapshot #{$orderItemId} conflicts with the canonical order item.");
            }
        }
    }
}