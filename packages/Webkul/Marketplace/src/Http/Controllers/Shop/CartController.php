<?php

namespace Webkul\Marketplace\Http\Controllers\Shop;

use Webkul\Marketplace\Exceptions\VendorOfferValidationException;
use Webkul\Marketplace\Http\Requests\AddVendorOfferToCartRequest;
use Webkul\Marketplace\Services\MarketplaceCartService;
use Webkul\Shop\Http\Controllers\Controller;

/**
 * The marketplace's own add-to-cart entry point — distinct from Bagisto's
 * generic `/cart/add`, since a marketplace add must resolve/validate a
 * specific vendor offer first. Delegates all actual cart persistence to
 * MarketplaceCartService -> Bagisto's Cart facade; holds no cart logic
 * itself.
 */
class CartController extends Controller
{
    public function __construct(
        protected MarketplaceCartService $cartService,
    ) {}

    public function store(AddVendorOfferToCartRequest $request)
    {
        try {
            $this->cartService->addOfferToCart(
                (int) $request->validated('vendor_product_id'),
                (int) $request->validated('product_id'),
                (int) $request->validated('quantity'),
            );
        } catch (VendorOfferValidationException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('shop.checkout.cart.index')->with('success', 'Added to cart.');
    }
}
