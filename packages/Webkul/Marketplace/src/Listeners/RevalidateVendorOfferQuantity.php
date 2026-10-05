<?php

namespace Webkul\Marketplace\Listeners;

use Webkul\Checkout\Contracts\CartItem;
use Webkul\Marketplace\Exceptions\VendorOfferValidationException;
use Webkul\Marketplace\Models\VendorProduct;

/**
 * `Cart::updateItems()` (the normal Bagisto "change quantity" cart flow —
 * shared by every item, marketplace or not) sets `$item->quantity` to the
 * NEW requested value and fires `checkout.cart.update.before` before
 * persisting it. For marketplace items this is the only place that can
 * re-validate the new quantity against the vendor's current offer quantity;
 * non-marketplace items (no `marketplace` key in `additional`) are
 * untouched.
 */
class RevalidateVendorOfferQuantity
{
    public function handle(CartItem $item): void
    {
        $vendorProductId = $item->additional['marketplace']['vendor_product_id'] ?? null;

        if (! $vendorProductId) {
            return;
        }

        $vendorProduct = VendorProduct::find($vendorProductId);

        if (! $vendorProduct) {
            throw new VendorOfferValidationException('This vendor offer is no longer available.');
        }

        if ($item->quantity > $vendorProduct->quantity) {
            throw new VendorOfferValidationException('The requested quantity exceeds what this vendor currently has available.');
        }
    }
}
