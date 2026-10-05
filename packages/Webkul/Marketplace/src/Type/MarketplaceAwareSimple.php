<?php

namespace Webkul\Marketplace\Type;

use Webkul\Checkout\Contracts\CartItem;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Product\DataTypes\CartItemValidationResult;
use Webkul\Product\Type\Simple;

/**
 * Bound over the core `Simple` type via the container (see
 * MarketplaceServiceProvider::register()) — `Webkul\Product\Type\Simple`
 * itself is never edited. Both overrides are no-ops unless the submitted
 * cart data carries `vendor_product_id`, so every non-marketplace Simple
 * product (B2C and B2B alike) behaves exactly as before.
 */
class MarketplaceAwareSimple extends Simple
{
    /**
     * Without this, two different vendors' offers of the SAME product_id
     * would be considered "the same cart line" by the parent implementation
     * (which only compares product_id/parent_id) and silently merge their
     * quantities together.
     */
    public function compareOptions($options1, $options2)
    {
        $vendorProductId1 = $options1['vendor_product_id'] ?? null;
        $vendorProductId2 = $options2['vendor_product_id'] ?? null;

        if ($vendorProductId1 !== $vendorProductId2) {
            return false;
        }

        return parent::compareOptions($options1, $options2);
    }

    /**
     * Runs Bagisto's normal preparation (quantity handling, core inventory
     * check, shape of the cart-item array) unchanged, then — only for a
     * marketplace add — overwrites the price-derived fields with
     * `VendorProduct.price` instead of `getFinalPrice()`'s base/customer-group/
     * catalog-rule price. The vendor's price is never taken from the request.
     */
    public function prepareForCart($data)
    {
        $products = parent::prepareForCart($data);

        if (
            is_string($products)
            || empty($data['vendor_product_id'])
        ) {
            return $products;
        }

        $vendorProduct = VendorProduct::find($data['vendor_product_id']);

        if (! $vendorProduct) {
            return $products;
        }

        $quantity = $products[0]['quantity'];
        $basePrice = (float) $vendorProduct->price;
        $price = core()->convertPrice($basePrice);

        $products[0]['price'] = $price;
        $products[0]['price_incl_tax'] = $price;
        $products[0]['base_price'] = $basePrice;
        $products[0]['base_price_incl_tax'] = $basePrice;
        $products[0]['total'] = $price * $quantity;
        $products[0]['total_incl_tax'] = $price * $quantity;
        $products[0]['base_total'] = $basePrice * $quantity;
        $products[0]['base_total_incl_tax'] = $basePrice * $quantity;

        return $products;
    }

    /**
     * `Cart::collectTotals()` calls this on every cart read/update and, by
     * default, recomputes the item's price fresh from `getFinalPrice()` —
     * which would silently overwrite the vendor's price set in
     * `prepareForCart()` above with Bagisto's own base/customer-group price
     * the very next time the cart page loads. For a marketplace item the
     * vendor's price is authoritative and must survive every reload, so
     * this skips the recomputation entirely (still runs the inactive-item
     * check). Non-marketplace items are unaffected (parent behavior).
     */
    public function validateCartItem(CartItem $item): CartItemValidationResult
    {
        if (empty($item->additional['vendor_product_id'])) {
            return parent::validateCartItem($item);
        }

        $validation = new CartItemValidationResult;

        if ($this->isCartItemInactive($item)) {
            $validation->itemIsInactive();
        }

        return $validation;
    }
}

