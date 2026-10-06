<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\B2BSuite\Helpers\CreditManager;
use Webkul\B2BSuite\Models\CompanyCredit;
use Webkul\B2BSuite\Models\CompanyCreditTransaction;
use Webkul\B2BSuite\Models\Customer as B2BCustomer;
use Webkul\Core\Models\Channel;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\InstallmentStatus;
use Webkul\Marketplace\Enums\PaymentPlanStatus;
use Webkul\Marketplace\Exceptions\MarketplaceCreditException;
use Webkul\Marketplace\Listeners\CreateMarketplacePaymentPlan;
use Webkul\Marketplace\Models\CompanyPaymentPolicy;
use Webkul\Marketplace\Models\Payment;
use Webkul\Marketplace\Models\PaymentPlan;
use Webkul\Marketplace\Services\MarketplaceCompanyPaymentPolicyService;
use Webkul\Marketplace\Services\MarketplaceCreditValidationService;
use Webkul\Marketplace\Services\MarketplacePaymentPlanService;
use Webkul\Marketplace\Services\MarketplacePaymentService;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderPayment;

uses(TestCase::class);

function phase12cConfig(string $code, string $value): void
{
    if (str_starts_with($code, 'b2b.')) {
        Config::set(substr($code, 4), $value === '1');
    }

    DB::table('core_config')->where('code', $code)->delete();

    DB::table('core_config')->insert([
        'code'         => $code,
        'value'        => $value,
        'channel_code' => str_starts_with($code, 'b2b.') ? core()->getRequestedChannelCode() : null,
        'locale_code'  => null,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function phase12cOrder(B2BCustomer $customer, string $grandTotal, ?string $paymentMethod = 'paybycredit'): Order
{
    $order = Order::create([
        'increment_id'                  => 'P12C-'.str()->upper(str()->random(12)),
        'status'                        => Order::STATUS_PENDING,
        'is_guest'                      => false,
        'customer_id'                   => $customer->id,
        'customer_type'                 => $customer::class,
        'customer_email'                => $customer->email,
        'customer_first_name'           => $customer->first_name,
        'customer_last_name'            => $customer->last_name,
        'channel_id'                    => 1,
        'channel_type'                  => Channel::class,
        'channel_name'                  => 'Default',
        'base_currency_code'            => 'EGP',
        'channel_currency_code'         => 'EGP',
        'order_currency_code'           => 'EGP',
        'total_item_count'              => 1,
        'total_qty_ordered'             => 1,
        'base_sub_total'                => $grandTotal,
        'sub_total'                     => $grandTotal,
        'base_discount_amount'          => '0.0000',
        'discount_amount'               => '0.0000',
        'base_shipping_amount'          => '0.0000',
        'shipping_amount'               => '0.0000',
        'base_shipping_discount_amount' => '0.0000',
        'shipping_discount_amount'      => '0.0000',
        'base_shipping_tax_amount'      => '0.0000',
        'shipping_tax_amount'           => '0.0000',
        'base_tax_amount'               => '0.0000',
        'tax_amount'                    => '0.0000',
        'base_grand_total'              => $grandTotal,
        'grand_total'                   => $grandTotal,
        'cart_id'                       => null,
    ]);

    if ($paymentMethod) {
        OrderPayment::create([
            'order_id' => $order->id,
            'method'   => $paymentMethod,
        ]);
    }

    return $order->fresh(['payment']);
}

function phase12cCredit(B2BCustomer $company, string $limit, string $outstanding = '0.0000', bool $allowExceedLimit = false, bool $status = true): CompanyCredit
{
    phase12cConfig('b2b.general.settings.active', '1');
    phase12cConfig('b2b.credit.settings.active', '1');

    return CompanyCredit::create([
        'company_id'           => $company->id,
        'credit_currency_code' => 'EGP',
        'credit_limit'         => $limit,
        'outstanding_balance'  => $outstanding,
        'allow_exceed_limit'   => $allowExceedLimit,
        'status'               => $status,
    ]);
}

function phase12cPolicy(B2BCustomer $company, int $maxTermDays = 30, bool $installmentsAllowed = true, int $maxInstallments = 3): CompanyPaymentPolicy
{
    return app(MarketplaceCompanyPaymentPolicyService::class)->createOrUpdate($company, $maxTermDays, $installmentsAllowed, $maxInstallments);
}

// --- Credit validation -------------------------------------------------

it('allows an order exactly at the available credit limit', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '1000.0000', '400.0000');

    app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('600.0000'));

    expect(true)->toBeTrue();
});

it('blocks an order that would exceed the available credit limit', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '1000.0000', '400.0000');

    expect(fn () => app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('600.0100')))
        ->toThrow(MarketplaceCreditException::class, 'exceeds its approved credit limit');
});

it('checks four-decimal outstanding exposure before rounding the order amount to cents', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '100.0050', '100.0040');

    expect(fn () => app(MarketplaceCreditValidationService::class)
        ->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('0.0100')))
        ->toThrow(MarketplaceCreditException::class, 'exceeds its approved credit limit');
});

it('ignores allow_exceed_limit and still enforces the hard credit limit for Marketplace orders', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '1000.0000', '400.0000', allowExceedLimit: true);

    expect(fn () => app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('600.0100')))
        ->toThrow(MarketplaceCreditException::class, 'exceeds its approved credit limit');
});

it('does not double-count an order whose B2B credit charge has already posted', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000', '1000.0000');
    $order = phase12cOrder($company, '500.0000');

    CompanyCreditTransaction::create([
        'company_credit_id'         => $credit->id,
        'operation'                 => CompanyCreditTransaction::OPERATION_PURCHASED,
        'amount'                    => '500.0000',
        'outstanding_balance_after' => '1000.0000',
        'available_credit_after'    => '0.0000',
        'credit_limit_after'        => '1000.0000',
        'order_id'                  => $order->id,
    ]);

    app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('500.0000'), $order->id);

    expect(true)->toBeTrue();
});

it('locks the existing B2B credit row while validating projected exposure', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '1000.0000', '100.0000');
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'b2b_company_credits') && str_contains($sql, 'for update')) {
            $queries[] = $sql;
        }
    });

    DB::transaction(function () use ($company) {
        app(MarketplaceCreditValidationService::class)
            ->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('100.0000'));
    });

    expect($queries)->not->toBeEmpty();
});

it('blocks credit validation when the company has no credit facility or it is inactive', function () {
    $companyWithout = phase12bCustomer('company');
    phase12cConfig('b2b.credit.settings.active', '1');

    expect(fn () => app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($companyWithout, 100))
        ->toThrow(MarketplaceCreditException::class, 'does not have a credit facility');

    $companyInactive = phase12bCustomer('company');
    phase12cCredit($companyInactive, '1000.0000', '0.0000', status: false);

    expect(fn () => app(MarketplaceCreditValidationService::class)->assertProjectedExposureWithinLimit($companyInactive, 100))
        ->toThrow(MarketplaceCreditException::class, 'credit facility is not active');
});

it('rejects a non-EGP B2B credit facility for Marketplace exposure', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000');
    $credit->update(['credit_currency_code' => 'USD']);

    expect(fn () => app(MarketplaceCreditValidationService::class)
        ->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('1.0000')))
        ->toThrow(MarketplaceCreditException::class, 'must use EGP');
});

it('does not treat an existing negative B2B balance as Marketplace advance credit', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000', '0.0000');
    $credit->update(['outstanding_balance' => '-1.0000']);

    expect(fn () => app(MarketplaceCreditValidationService::class)
        ->assertProjectedExposureWithinLimit($company, EgpAmount::toMinorUnits('1.0000')))
        ->toThrow(MarketplaceCreditException::class, 'cannot be used as Marketplace advance credit');

    expect(fn () => app(MarketplacePaymentService::class)->recordPayment($company, '1.0000', now()->toDateString()))
        ->toThrow(MarketplaceCreditException::class, 'negative B2B balance');
});

// --- Company payment policy ---------------------------------------------

it('enforces exactly one payment policy per company', function () {
    $company = phase12bCustomer('company');
    $service = app(MarketplaceCompanyPaymentPolicyService::class);

    $first = $service->createOrUpdate($company, 30, true, 3);
    $second = $service->createOrUpdate($company, 45, false, 1);

    expect(CompanyPaymentPolicy::where('company_id', $company->id)->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->maximum_term_days)->toBe(45)
        ->and($second->installments_allowed)->toBeFalse();
});

// --- Payment plan creation ------------------------------------------------

it('creates one payment plan per order, snapshotting the agreed term and splitting installments without losing a cent', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 60, installmentsAllowed: true, maxInstallments: 3);
    $order = phase12cOrder($company, '1000.0003');

    $plan = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 30, installmentCount: 3);

    expect($plan->approved_term_days)->toBe(30)
        ->and($plan->status)->toBe(PaymentPlanStatus::ACTIVE->value)
        ->and(EgpAmount::toMinorUnits((string) $plan->total_amount))->toBe(EgpAmount::toMinorUnits('1000.0003'))
        ->and($plan->installments)->toHaveCount(3);

    $sum = $plan->installments->sum(fn ($installment) => EgpAmount::toMinorUnits((string) $installment->amount));
    expect($sum)->toBe(EgpAmount::toMinorUnits('1000.0003'))
        ->and($plan->installments->pluck('installment_number')->all())->toBe([1, 2, 3]);
});

it('is idempotent for repeated plan creation on the same order and rejects conflicting re-creation', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 60, installmentsAllowed: true, maxInstallments: 3);
    $order = phase12cOrder($company, '500.0000');

    $service = app(MarketplacePaymentPlanService::class);
    $plan = $service->createForOrder($order, $company, requestedTermDays: 30, installmentCount: 1);
    $again = $service->createForOrder($order, $company, requestedTermDays: 30, installmentCount: 1);

    expect($again->id)->toBe($plan->id)
        ->and(PaymentPlan::where('order_id', $order->id)->count())->toBe(1);

    expect(fn () => $service->createForOrder($order, $company, requestedTermDays: 45, installmentCount: 1))
        ->toThrow(MarketplaceCreditException::class, 'conflicts with the requested parameters');
});

it('keeps an existing payment plan historically immune to later payment policy changes', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 60, installmentsAllowed: true, maxInstallments: 3);
    $order = phase12cOrder($company, '500.0000');

    $plan = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 60, installmentCount: 1);

    phase12cPolicy($company, maxTermDays: 10, installmentsAllowed: false, maxInstallments: 1);
    $retry = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company);

    expect($plan->fresh()->approved_term_days)->toBe(60)
        ->and($retry->id)->toBe($plan->id);
});

it('rejects a requested payment term exceeding the company policy maximum', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 15);
    $order = phase12cOrder($company, '500.0000');

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 16))
        ->toThrow(MarketplaceCreditException::class, 'exceeds company');
});

it('rejects installments when the company policy disallows them or exceeds the maximum', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: false, maxInstallments: 1);
    $order = phase12cOrder($company, '500.0000');

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, installmentCount: 2))
        ->toThrow(MarketplaceCreditException::class, 'not permitted to use installments');

    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: true, maxInstallments: 2);

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, installmentCount: 3))
        ->toThrow(MarketplaceCreditException::class, 'exceeds company');
});

it('rejects an order amount too small to support the requested installment count', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: true, maxInstallments: 5);
    $order = phase12cOrder($company, '0.0300');

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, installmentCount: 5))
        ->toThrow(MarketplaceCreditException::class, 'too small');
});

it('rejects a zero-value order payment plan', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company);
    $order = phase12cOrder($company, '0.0000');

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company))
        ->toThrow(MarketplaceCreditException::class, 'positive order amount');
});

it('blocks payment plan creation when the order would exceed the company credit limit', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '500.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 30);
    $order = phase12cOrder($company, '500.0100');

    expect(fn () => app(MarketplacePaymentPlanService::class)->createForOrder($order, $company))
        ->toThrow(MarketplaceCreditException::class, 'exceeds its approved credit limit')
        ->and(PaymentPlan::where('order_id', $order->id)->exists())->toBeFalse();
});

// --- Order-creation integration (listener) --------------------------------

it('automatically creates a payment plan for a paybycredit order via the checkout.order.save.after listener', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '0.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: false, maxInstallments: 1);
    $order = phase12cOrder($company, '750.0000', 'paybycredit');

    app(CreateMarketplacePaymentPlan::class)->handle($order);

    $plan = PaymentPlan::where('order_id', $order->id)->first();
    expect($plan)->not->toBeNull()
        ->and(EgpAmount::toMinorUnits((string) $plan->total_amount))->toBe(EgpAmount::toMinorUnits('750.0000'));
});

it('creates the plan and posts exactly one B2B purchase within the order transaction', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '10000.0000', '0.0000');
    phase12cConfig('b2b.general.settings.active', '1');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: false, maxInstallments: 1);
    $order = phase12cOrder($company, '750.0000', 'paybycredit');

    expect((bool) core()->getConfigData('b2b.general.settings.active'))->toBeTrue()
        ->and(app(CreditManager::class)->isActive())->toBeTrue();

    DB::transaction(function () use ($order) {
        app(CreateMarketplacePaymentPlan::class)->handle($order);
        app(Webkul\B2BSuite\Listeners\Order::class)->afterCreated($order);
    });

    expect(PaymentPlan::where('order_id', $order->id)->count())->toBe(1)
        ->and(CompanyCreditTransaction::query()
            ->where('company_credit_id', $credit->id)
            ->where('operation', CompanyCreditTransaction::OPERATION_PURCHASED)
            ->where('order_id', $order->id)
            ->count())->toBe(1)
        ->and(EgpAmount::toMinorUnits((string) $credit->fresh()->outstanding_balance))->toBe(EgpAmount::toMinorUnits('750.0000'));
});

it('does nothing for orders not paid by company credit', function () {
    $company = phase12bCustomer('company');
    $order = phase12cOrder($company, '750.0000', 'cashondelivery');

    app(CreateMarketplacePaymentPlan::class)->handle($order);

    expect(PaymentPlan::where('order_id', $order->id)->exists())->toBeFalse();
});

// --- Manual payments and allocation ---------------------------------------

it('records a manual payment and atomically reduces the B2B outstanding balance', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000', '600.0000');

    $payment = app(MarketplacePaymentService::class)->recordPayment($company, '250.0000', now()->toDateString(), 'REF-1', 'bank_transfer', 'partial settlement');

    expect(EgpAmount::toMinorUnits((string) $payment->amount))->toBe(EgpAmount::toMinorUnits('250.0000'))
        ->and(EgpAmount::toMinorUnits((string) $credit->fresh()->outstanding_balance))->toBe(EgpAmount::toMinorUnits('350.0000'));
});

it('allocates a payment across installments, marking them partially paid then paid, and completes the plan', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '1000.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: true, maxInstallments: 2);
    $order = phase12cOrder($company, '1000.0000');

    $plan = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 30, installmentCount: 2);
    [$first, $second] = $plan->installments->all();

    $paymentService = app(MarketplacePaymentService::class);
    $payment = $paymentService->recordPayment($company, '1000.0000', now()->toDateString());

    $paymentService->allocate($payment, $first, (string) $first->amount);
    expect($first->fresh()->status)->toBe(InstallmentStatus::PAID->value)
        ->and($plan->fresh()->status)->toBe(PaymentPlanStatus::ACTIVE->value);

    $secondAmountMinor = EgpAmount::toMinorUnits((string) $second->amount);

    $paymentService->allocate($payment, $second, '100.0000');
    expect($second->fresh()->status)->toBe(InstallmentStatus::PARTIALLY_PAID->value)
        ->and($plan->fresh()->status)->toBe(PaymentPlanStatus::ACTIVE->value);

    $remainingMinor = $secondAmountMinor - EgpAmount::toMinorUnits('100.0000');
    $paymentService->allocate($payment->fresh(), $second->fresh(), EgpAmount::toDatabaseDecimal($remainingMinor));
    expect($second->fresh()->status)->toBe(InstallmentStatus::PAID->value)
        ->and($plan->fresh()->status)->toBe(PaymentPlanStatus::COMPLETED->value);
});

it('rejects an allocation that exceeds the installment remaining amount', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '1000.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: false, maxInstallments: 1);
    $order = phase12cOrder($company, '500.0000');

    $plan = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 30);
    $installment = $plan->installments->first();

    $paymentService = app(MarketplacePaymentService::class);
    $payment = $paymentService->recordPayment($company, '500.0000', now()->toDateString());

    expect(fn () => $paymentService->allocate($payment, $installment, '500.0100'))
        ->toThrow(MarketplaceCreditException::class)
        ->and(EgpAmount::toMinorUnits((string) $payment->fresh()->allocated_amount))->toBe(0)
        ->and(EgpAmount::toMinorUnits((string) $installment->fresh()->paid_amount))->toBe(0);
});

it('rejects an allocation that exceeds the payment remaining unallocated amount', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '10000.0000', '1000.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: true, maxInstallments: 2);
    $order = phase12cOrder($company, '1000.0000');

    $plan = app(MarketplacePaymentPlanService::class)->createForOrder($order, $company, requestedTermDays: 30, installmentCount: 2);
    [$first, $second] = $plan->installments->all();

    $paymentService = app(MarketplacePaymentService::class);
    $payment = $paymentService->recordPayment($company, '300.0000', now()->toDateString());

    $paymentService->allocate($payment, $first, '300.0000');

    expect(fn () => $paymentService->allocate($payment->fresh(), $second, '0.0100'))
        ->toThrow(MarketplaceCreditException::class, 'remaining unallocated amount');
});

it('rejects a zero or negative recorded payment amount', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '1000.0000', '0.0000');

    expect(fn () => app(MarketplacePaymentService::class)->recordPayment($company, '0.0000', now()->toDateString()))
        ->toThrow(MarketplaceCreditException::class, 'greater than zero');
});

it('rejects a manual payment larger than the outstanding balance instead of creating advance credit', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000', '100.0000');

    expect(fn () => app(MarketplacePaymentService::class)->recordPayment($company, '100.0100', now()->toDateString()))
        ->toThrow(MarketplaceCreditException::class, 'cannot exceed the company credit outstanding balance')
        ->and(Payment::count())->toBe(0)
        ->and(EgpAmount::toMinorUnits((string) $credit->fresh()->outstanding_balance))->toBe(EgpAmount::toMinorUnits('100.0000'));
});

it('does not round a fractional B2B balance up when validating manual payments', function () {
    $company = phase12bCustomer('company');
    $credit = phase12cCredit($company, '1000.0000', '100.0050');

    expect(fn () => app(MarketplacePaymentService::class)->recordPayment($company, '100.0100', now()->toDateString()))
        ->toThrow(MarketplaceCreditException::class, 'cannot exceed the company credit outstanding balance')
        ->and(EgpAmount::toPrecisionUnits((string) $credit->fresh()->outstanding_balance))->toBe(EgpAmount::toPrecisionUnits('100.0050'));
});

it('accepts multiple payments against one installment without losing partial allocations', function () {
    $company = phase12bCustomer('company');
    phase12cCredit($company, '5000.0000', '1000.0000');
    phase12cPolicy($company, maxTermDays: 30, installmentsAllowed: false, maxInstallments: 1);
    $order = phase12cOrder($company, '500.0000');
    $installment = app(MarketplacePaymentPlanService::class)
        ->createForOrder($order, $company)
        ->installments
        ->first();

    $paymentService = app(MarketplacePaymentService::class);
    $firstPayment = $paymentService->recordPayment($company, '100.0000', now()->toDateString());
    $secondPayment = $paymentService->recordPayment($company, '400.0000', now()->toDateString());

    $paymentService->allocate($firstPayment, $installment, '100.0000');
    $paymentService->allocate($secondPayment, $installment->fresh(), '400.0000');

    expect($installment->fresh()->status)->toBe(InstallmentStatus::PAID->value)
        ->and($installment->allocations()->count())->toBe(2);
});

it('rejects allocating a company payment to another company installment', function () {
    $companyA = phase12bCustomer('company');
    $companyB = phase12bCustomer('company');
    phase12cCredit($companyA, '5000.0000', '1000.0000');
    phase12cCredit($companyB, '5000.0000', '1000.0000');
    phase12cPolicy($companyA);
    $order = phase12cOrder($companyA, '500.0000');
    $installment = app(MarketplacePaymentPlanService::class)->createForOrder($order, $companyA)->installments->first();
    $payment = app(MarketplacePaymentService::class)->recordPayment($companyB, '100.0000', now()->toDateString());

    expect(fn () => app(MarketplacePaymentService::class)->allocate($payment, $installment, '100.0000'))
        ->toThrow(MarketplaceCreditException::class, 'same company')
        ->and(EgpAmount::toMinorUnits((string) $installment->fresh()->paid_amount))->toBe(0);
});
