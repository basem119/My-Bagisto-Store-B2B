<?php

use Illuminate\Support\Facades\DB;
use Webkul\B2BSuite\Models\Customer as B2BCustomer;
use Webkul\Checkout\Models\Cart;
use Webkul\Checkout\Models\CartItem;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\DataTypes\FinancialRate;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;
use Webkul\Marketplace\Listeners\AllocateCompanyFeeToInvoice;
use Webkul\Marketplace\Listeners\ApplyCompanyPlatformFeeToCart;
use Webkul\Marketplace\Models\FinancialEntry;
use Webkul\Marketplace\Models\FinancialTransaction;
use Webkul\Marketplace\Models\InvoiceFinancial;
use Webkul\Marketplace\Models\OrderFinancial;
use Webkul\Marketplace\Models\OrderItemFinancial;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Marketplace\Services\MarketplaceCompanyResolver;
use Webkul\Marketplace\Services\MarketplaceOrderFinancializationService;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\InvoiceRepository;

uses(Tests\TestCase::class);

function phase12bCustomer(string $type = 'user'): B2BCustomer
{
    return B2BCustomer::create([
        'first_name' => 'Phase',
        'last_name' => 'Twelve B',
        'email' => 'p12b-'.str()->uuid().'@example.test',
        'password' => bcrypt('test-password'),
        'customer_group_id' => 2,
        'channel_id' => 1,
        'status' => 1,
        'is_verified' => 1,
        'type' => $type,
    ]);
}

function phase12bConfig(string $code, string $value): void
{
    DB::table('core_config')->where('code', $code)->delete();

    DB::table('core_config')->insert([
        'code' => $code,
        'value' => $value,
        'channel_code' => null,
        'locale_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function phase12bVendor(string $label, ?string $commissionRate = null): Vendor
{
    return Vendor::create([
        'name' => $label,
        'slug' => str()->slug($label).'-'.str()->random(8),
        'email' => str()->uuid().'@example.test',
        'status' => VendorStatus::ACTIVE->value,
        'commission_rate' => $commissionRate,
    ]);
}

function phase12bOrder(B2BCustomer $customer, array $items, array $totals): Order
{
    $order = Order::create([
        'increment_id' => 'P12B-'.str()->upper(str()->random(12)),
        'status' => Order::STATUS_PENDING,
        'is_guest' => false,
        'customer_id' => $customer->id,
        'customer_type' => $customer::class,
        'customer_email' => $customer->email,
        'customer_first_name' => $customer->first_name,
        'customer_last_name' => $customer->last_name,
        'channel_id' => 1,
        'channel_type' => Webkul\Core\Models\Channel::class,
        'channel_name' => 'Default',
        'base_currency_code' => 'EGP',
        'channel_currency_code' => 'EGP',
        'order_currency_code' => 'EGP',
        'total_item_count' => count($items),
        'total_qty_ordered' => count($items),
        'base_sub_total' => $totals['gross'],
        'sub_total' => $totals['gross'],
        'base_discount_amount' => $totals['discount'],
        'discount_amount' => $totals['discount'],
        'base_shipping_amount' => $totals['delivery'],
        'shipping_amount' => $totals['delivery'],
        'base_shipping_discount_amount' => $totals['delivery_discount'] ?? 0,
        'shipping_discount_amount' => $totals['delivery_discount'] ?? 0,
        'base_shipping_tax_amount' => $totals['delivery_tax'],
        'shipping_tax_amount' => $totals['delivery_tax'],
        'base_tax_amount' => $totals['tax'],
        'tax_amount' => $totals['tax'],
        'base_grand_total' => $totals['customer_total'],
        'grand_total' => $totals['customer_total'],
        'cart_id' => null,
    ]);

    OrderAddress::create([
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'address_type' => OrderAddress::ADDRESS_TYPE_BILLING,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'address1' => '1 Test Street',
        'city' => 'Cairo',
        'country' => 'EG',
        'email' => $customer->email,
    ]);

    foreach ($items as $item) {
        OrderItem::create([
            'order_id' => $order->id,
            'sku' => $item['sku'],
            'type' => 'simple',
            'name' => $item['name'],
            'qty_ordered' => $item['quantity'] ?? 1,
            'price' => $item['price'],
            'base_price' => $item['price'],
            'total' => $item['total'],
            'base_total' => $item['total'],
            'discount_amount' => $item['discount'],
            'base_discount_amount' => $item['discount'],
            'tax_amount' => $item['tax'],
            'base_tax_amount' => $item['tax'],
            'product_id' => null,
            'product_type' => Webkul\Product\Models\Product::class,
            'additional' => [
                'marketplace' => [
                    'vendor_id' => $item['vendor']->id,
                    'vendor_product_id' => $item['offer_id'],
                    'vendor_name' => $item['vendor_snapshot_name'],
                    'vendor_sku' => $item['vendor_snapshot_sku'],
                    'vendor_price' => $item['price'],
                ],
                'marketplace_financial' => $item['fee_snapshot'],
            ],
        ]);
    }

    return $order->fresh();
}

function phase12bFeeSnapshot(string $rate, int $discountedSubtotalPrecision): array
{
    return [
        'company_fee_rate' => $rate,
        'company_fee_amount_minor' => FinancialRate::percentOfPrecisionUnits($discountedSubtotalPrecision, $rate),
        'discounted_product_subtotal_minor' => EgpAmount::precisionUnitsToMinorUnits($discountedSubtotalPrecision),
        'discounted_product_subtotal_precision' => $discountedSubtotalPrecision,
    ];
}

it('resolves company customers to themselves and company users through exactly one B2B membership', function () {
    $company = phase12bCustomer('company');
    $member = phase12bCustomer('user');
    $member->companies()->attach($company->id);
    $resolver = app(MarketplaceCompanyResolver::class);

    expect($resolver->resolve($company)->id)->toBe($company->id)
        ->and($resolver->resolve($member)->id)->toBe($company->id)
        ->and($resolver->resolve($member, $company->id)->id)->toBe($company->id);
});

it('blocks company-less, multi-company and cart-company-mismatch resolution', function () {
    $member = phase12bCustomer('user');
    $companyA = phase12bCustomer('company');
    $companyB = phase12bCustomer('company');
    $member->companies()->attach([$companyA->id, $companyB->id]);

    expect(fn () => app(MarketplaceCompanyResolver::class)->resolve($member))
        ->toThrow(MarketplaceFinancializationException::class, 'multiple B2B companies');

    $unlinkedMember = phase12bCustomer('user');
    expect(fn () => app(MarketplaceCompanyResolver::class)->resolve($unlinkedMember))
        ->toThrow(MarketplaceFinancializationException::class, 'does not belong');

    $companyCustomer = phase12bCustomer('company');
    expect(fn () => app(MarketplaceCompanyResolver::class)->resolve($companyCustomer, $companyA->id))
        ->toThrow(MarketplaceFinancializationException::class, 'does not match');
});

it('rounds a discounted four-decimal subtotal only after aggregation', function () {
    $precision = EgpAmount::toPrecisionUnits('0.0050') + EgpAmount::toPrecisionUnits('0.0050');

    expect(EgpAmount::precisionUnitsToMinorUnits($precision))->toBe(1)
        ->and(FinancialRate::percentOfPrecisionUnits($precision, '100.0000'))->toBe(1)
        ->and(FinancialRate::percentOfPrecisionUnits(EgpAmount::toPrecisionUnits('1.0050'), '10.0000'))->toBe(10);
});

it('adds a non-taxable company fee to the saved Bagisto cart grand total and snapshots its rate', function () {
    $company = phase12bCustomer('company');
    phase12bConfig('marketplace.financial.company_fee_rate', '100');
    $productId = DB::table('products')->value('id');
    expect($productId)->not->toBeNull();

    $cart = Cart::create([
        'customer_id' => $company->id,
        'customer_email' => $company->email,
        'customer_first_name' => $company->first_name,
        'customer_last_name' => $company->last_name,
        'base_currency_code' => 'EGP',
        'channel_currency_code' => 'EGP',
        'cart_currency_code' => 'EGP',
        'global_currency_code' => 'EGP',
        'channel_id' => 1,
        'is_guest' => false,
        'is_active' => true,
        'sub_total' => '0.0100',
        'base_sub_total' => '0.0100',
        'grand_total' => '0.0100',
        'base_grand_total' => '0.0100',
    ]);

    foreach ([1, 2] as $line) {
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $productId,
            'quantity' => 1,
            'sku' => "P12B-CART-{$line}",
            'type' => 'simple',
            'name' => "Precision item {$line}",
            'price' => '0.0050',
            'base_price' => '0.0050',
            'total' => '0.0050',
            'base_total' => '0.0050',
            'discount_amount' => 0,
            'base_discount_amount' => 0,
            'additional' => $line === 1 ? ['marketplace' => ['vendor_id' => 1]] : [],
        ]);
    }

    $cart->refresh()->load(['items', 'customer']);
    app(ApplyCompanyPlatformFeeToCart::class)->handle($cart);
    $cart->refresh()->load('items');

    expect((string) $cart->base_grand_total)->toBe('0.0200')
        ->and((int) $cart->items->first()->additional['marketplace_financial']['company_fee_amount_minor'])->toBe(1)
        ->and((int) $cart->items->first()->additional['marketplace_financial']['discounted_product_subtotal_precision'])->toBe(100);
});

it('financializes one multi-vendor order using historical item identity and discounted commission bases', function () {
    phase12bConfig('marketplace.financial.company_fee_rate', '5');
    phase12bConfig('marketplace.financial.default_vendor_commission_rate', '12');

    $company = phase12bCustomer('company');
    $member = phase12bCustomer('user');
    $member->companies()->attach($company->id);

    $vendorA = phase12bVendor('Current Vendor A', '10');
    $vendorB = phase12bVendor('Current Vendor B');
    $offerIdA = VendorProduct::create([
        'vendor_id' => $vendorA->id,
        'product_id' => DB::table('products')->value('id'),
        'vendor_sku' => 'LIVE-A',
        'price' => '5000',
        'quantity' => 4,
        'status' => 'active',
    ])->id;
    $offerIdB = VendorProduct::create([
        'vendor_id' => $vendorB->id,
        'product_id' => DB::table('products')->orderByDesc('id')->value('id'),
        'vendor_sku' => 'LIVE-B',
        'price' => '3000',
        'quantity' => 5,
        'status' => 'active',
    ])->id;

    $items = [
        [
            'vendor' => $vendorA,
            'offer_id' => $offerIdA,
            'vendor_snapshot_name' => 'Purchased Vendor A',
            'vendor_snapshot_sku' => 'ORDER-A',
            'sku' => 'PRODUCT-A',
            'name' => 'Product A',
            'price' => '5000.0000',
            'total' => '5000.0000',
            'discount' => '500.0000',
            'tax' => '50.0000',
            'fee_snapshot' => phase12bFeeSnapshot('5.0000', EgpAmount::toPrecisionUnits('7500.0000')),
        ],
        [
            'vendor' => $vendorB,
            'offer_id' => $offerIdB,
            'vendor_snapshot_name' => 'Purchased Vendor B',
            'vendor_snapshot_sku' => 'ORDER-B',
            'sku' => 'PRODUCT-B',
            'name' => 'Product B',
            'price' => '3000.0000',
            'total' => '3000.0000',
            'discount' => '0.0000',
            'tax' => '20.0000',
            'fee_snapshot' => phase12bFeeSnapshot('5.0000', EgpAmount::toPrecisionUnits('7500.0000')),
        ],
    ];

    $order = phase12bOrder($member, $items, [
        'gross' => '8000.0000',
        'discount' => '500.0000',
        'delivery' => '200.0000',
        'delivery_discount' => '0.0000',
        'delivery_tax' => '30.0000',
        'tax' => '100.0000',
        'customer_total' => '8175.0000',
    ]);

    $service = app(MarketplaceOrderFinancializationService::class);
    $financial = $service->financialize($order);

    expect($financial->company_id)->toBe($company->id)
        ->and(EgpAmount::toPrecisionUnits((string) $financial->product_subtotal))->toBe(EgpAmount::toPrecisionUnits('8000.0000'))
        ->and(EgpAmount::toPrecisionUnits((string) $financial->product_discount_amount))->toBe(EgpAmount::toPrecisionUnits('500.0000'))
        ->and(EgpAmount::toPrecisionUnits((string) $financial->discounted_product_subtotal))->toBe(EgpAmount::toPrecisionUnits('7500.0000'))
        ->and((string) $financial->company_fee_rate)->toBe('5.0000')
        ->and(EgpAmount::toMinorUnits((string) $financial->company_fee_amount))->toBe(37500)
        ->and(EgpAmount::toMinorUnits((string) $financial->delivery_amount))->toBe(20000)
        ->and(EgpAmount::toMinorUnits((string) $financial->product_tax_amount))->toBe(7000)
        ->and(EgpAmount::toMinorUnits((string) $financial->delivery_tax_amount))->toBe(3000)
        ->and(EgpAmount::toMinorUnits((string) $financial->customer_financial_total))->toBe(817500)
        ->and($financial->items)->toHaveCount(2);

    $snapshotA = $financial->items->firstWhere('vendor_id', $vendorA->id);
    $snapshotB = $financial->items->firstWhere('vendor_id', $vendorB->id);

    expect($snapshotA->vendor_name)->toBe('Purchased Vendor A')
        ->and($snapshotA->vendor_sku)->toBe('ORDER-A')
        ->and(EgpAmount::toMinorUnits((string) $snapshotA->discounted_product_basis))->toBe(450000)
        ->and(EgpAmount::toMinorUnits((string) $snapshotA->commission_amount))->toBe(45000)
        ->and(EgpAmount::toMinorUnits((string) $snapshotA->vendor_net_amount))->toBe(405000)
        ->and($snapshotB->vendor_name)->toBe('Purchased Vendor B')
        ->and(EgpAmount::toMinorUnits((string) $snapshotB->commission_amount))->toBe(36000)
        ->and(EgpAmount::toMinorUnits((string) $snapshotB->vendor_net_amount))->toBe(264000);

    $vendorA->update(['name' => 'Renamed Vendor A', 'commission_rate' => '50']);
    $vendorB->update(['commission_rate' => '90']);
    VendorProduct::whereKey($offerIdA)->update(['price' => '1', 'vendor_sku' => 'CHANGED-A']);
    phase12bConfig('marketplace.financial.company_fee_rate', '90');

    $retry = $service->financialize($order->fresh());

    expect($retry->id)->toBe($financial->id)
        ->and(OrderFinancial::where('order_id', $order->id)->count())->toBe(1)
        ->and(OrderItemFinancial::where('order_financial_id', $financial->id)->count())->toBe(2)
        ->and((string) $retry->company_fee_rate)->toBe('5.0000')
        ->and((string) $retry->items->firstWhere('vendor_id', $vendorA->id)->commission_rate)->toBe('10.0000')
        ->and($retry->items->firstWhere('vendor_id', $vendorA->id)->vendor_name)->toBe('Purchased Vendor A')
        ->and(FinancialTransaction::count())->toBe(0)
        ->and(FinancialEntry::count())->toBe(0)
        ->and(DB::table('b2b_company_credit_transactions')->count())->toBe(0);
});

it('allocates the historical company fee across partial Bagisto invoices without fake invoice items', function () {
    phase12bConfig('marketplace.financial.company_fee_rate', '5');
    phase12bConfig('marketplace.financial.default_vendor_commission_rate', '10');

    $company = phase12bCustomer('company');
    $vendor = phase12bVendor('Invoice Vendor', '10');
    $offerId = VendorProduct::create([
        'vendor_id' => $vendor->id,
        'product_id' => DB::table('products')->value('id'),
        'vendor_sku' => 'INVOICE-SKU',
        'price' => '7500',
        'quantity' => 10,
        'status' => 'active',
    ])->id;

    $itemData = [
        [
            'vendor' => $vendor,
            'offer_id' => $offerId,
            'vendor_snapshot_name' => 'Invoice Vendor',
            'vendor_snapshot_sku' => 'INVOICE-SKU',
            'sku' => 'INVOICE-PRODUCT-A',
            'name' => 'Invoice product A',
            'price' => '5000.0000',
            'total' => '5000.0000',
            'discount' => '500.0000',
            'tax' => '50.0000',
            'fee_snapshot' => phase12bFeeSnapshot('5.0000', EgpAmount::toPrecisionUnits('7500.0000')),
        ],
        [
            'vendor' => $vendor,
            'offer_id' => $offerId,
            'vendor_snapshot_name' => 'Invoice Vendor',
            'vendor_snapshot_sku' => 'INVOICE-SKU',
            'sku' => 'INVOICE-PRODUCT-B',
            'name' => 'Invoice product B',
            'price' => '3000.0000',
            'total' => '3000.0000',
            'discount' => '0.0000',
            'tax' => '20.0000',
            'fee_snapshot' => phase12bFeeSnapshot('5.0000', EgpAmount::toPrecisionUnits('7500.0000')),
        ],
    ];

    $order = phase12bOrder($company, $itemData, [
        'gross' => '8000.0000',
        'discount' => '500.0000',
        'delivery' => '200.0000',
        'delivery_discount' => '0.0000',
        'delivery_tax' => '30.0000',
        'tax' => '100.0000',
        'customer_total' => '8175.0000',
    ]);
    app(MarketplaceOrderFinancializationService::class)->financialize($order);

    $orderItems = $order->items()->orderBy('id')->get();
    $invoiceRepository = app(InvoiceRepository::class);
    $invoiceA = $invoiceRepository->create([
        'order_id' => $order->id,
        'invoice' => ['items' => [$orderItems[0]->id => 1]],
    ]);
    $invoiceB = $invoiceRepository->create([
        'order_id' => $order->id,
        'invoice' => ['items' => [$orderItems[1]->id => 1]],
    ]);

    $allocationA = InvoiceFinancial::where('invoice_id', $invoiceA->id)->firstOrFail();
    $allocationB = InvoiceFinancial::where('invoice_id', $invoiceB->id)->firstOrFail();

    expect(EgpAmount::toMinorUnits((string) $allocationA->company_fee_amount))->toBe(22500)
        ->and(EgpAmount::toMinorUnits((string) $allocationB->company_fee_amount))->toBe(15000)
        ->and(EgpAmount::toMinorUnits((string) $invoiceA->fresh()->base_grand_total))->toBe(500500)
        ->and(EgpAmount::toMinorUnits((string) $invoiceB->fresh()->base_grand_total))->toBe(317000)
        ->and(DB::table('invoice_items')->where('invoice_id', $invoiceA->id)->count())->toBe(1)
        ->and(DB::table('invoice_items')->where('invoice_id', $invoiceB->id)->count())->toBe(1)
        ->and(EgpAmount::toMinorUnits((string) InvoiceFinancial::where('order_financial_id', $allocationA->order_financial_id)->sum('company_fee_amount')))->toBe(37500);
});
