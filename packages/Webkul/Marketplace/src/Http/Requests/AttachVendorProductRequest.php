<?php

namespace Webkul\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Vendor is attaching an offer to an EXISTING Bagisto product (identified by
 * SKU, not a raw product_id) — never creates a product. Duplicate-offer
 * detection lives in VendorProductService, not here (that's a business
 * invariant, not input validation).
 */
class AttachVendorProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'exists:products,sku'],
            'vendor_sku' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
        ];
    }

    /**
     * A configurable product has no price/SKU of its own (its variants do),
     * and a disabled product shouldn't be sellable by anyone — reject both
     * rather than letting an offer attach to a product it can't logically
     * represent.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sku = $this->input('sku');

            if (! $sku || $validator->errors()->has('sku')) {
                return;
            }

            $product = app(ProductRepository::class)->findOneByField('sku', $sku);

            if (! $product) {
                return;
            }

            if ($product->type === 'configurable') {
                $validator->errors()->add('sku', 'A configurable product has no offer of its own — select a specific variant SKU instead.');
            }

            if (! $product->status) {
                $validator->errors()->add('sku', 'This product is disabled and cannot be offered.');
            }
        });
    }
}
