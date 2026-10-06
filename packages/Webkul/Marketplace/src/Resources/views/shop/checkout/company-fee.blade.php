@php
    $companyFeeRate = core()->getConfigData('marketplace.financial.company_fee_rate') ?: 0;
    $customer = auth()->guard('customer')->user();
    $belongsToCompany = $customer && (
        ($customer->type ?? null) === 'company'
        || (method_exists($customer, 'companies') && $customer->companies()->exists())
    );
@endphp

@if ($belongsToCompany && (float) $companyFeeRate > 0)
    <div class="flex justify-between text-right" data-marketplace-company-fee>
        <p class="text-base max-sm:text-sm">@lang('marketplace::app.shop.checkout.company-fee')</p>
        <p class="text-base font-medium max-sm:text-sm">
            + <span v-text="((cart.sub_total - cart.items_discount_amount) * {{ Illuminate\Support\Js::from((float) $companyFeeRate) }} / 100).toFixed(2)"></span> EGP
        </p>
    </div>
@endif