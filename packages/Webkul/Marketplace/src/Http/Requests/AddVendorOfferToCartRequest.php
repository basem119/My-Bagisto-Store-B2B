<?php

namespace Webkul\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only — existence/eligibility/ownership/quantity-available
 * are business invariants enforced by MarketplaceCartService, not here.
 */
class AddVendorOfferToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_product_id' => ['required', 'integer', 'exists:marketplace_vendor_products,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
