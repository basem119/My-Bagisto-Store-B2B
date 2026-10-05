<?php

namespace Webkul\Marketplace\Type;

use Webkul\Checkout\Contracts\CartItem;
use Webkul\Marketplace\Enums\VendorProductStatus;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Product\DataTypes\CartItemValidationResult;
use Webkul\Product\Type\Simple;

/**
 * Bound over the core `Simple` type via the container (see
 * MarketplaceServiceProvider::register()) — `Webkul\Product\Type\Simple`
 * itself is never edited. Every override is a no-op unless the submitted/
 * stored cart data carries `additional.marketplace`, so every
 * non-marketplace Simple product (B2C and B2B alike) behaves exactly as
 * before.
 *
 * `additional.marketplace` shape (both `cart_items.additional` and, once
 * copied through at checkout, `order_items.additional` — see
 * OrderItemResource, unmodified):
 *
 *   [
 *       'vendor_product_id' => int,
 *       'vendor_id'         => int,
 *       'vendor_name'       => string,  // snapshot — vendor may rename later
 *       'vendor_sku'        => ?string, // snapshot — vendor may re-SKU later
 *       'vendor_price'      => float,   // snapshot — redundant with price/base_price,
 *                                       // kept explicit so order-processing code never
 *                                       // has to guess which price column is "the vendor's"
 *   ]
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
        $vendorProductId1 = $options1['marketplace']['vendor_product_id'] ?? null;
        $vendorProductId2 = $options2['marketplace']['vendor_product_id'] ?? null;

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
     * catalog-rule price, and stores the purchase-time vendor snapshot. The
     * vendor's price is never taken from the request.
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

        $vendorProduct = VendorProduct::with('vendor')->find($data['vendor_product_id']);

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

        $products[0]['additional']['marketplace'] = [
            'vendor_product_id' => $vendorProduct->id,
            'vendor_id' => $vendorProduct->vendor_id,
            'vendor_name' => $vendorProduct->vendor?->name,
            'vendor_sku' => $vendorProduct->vendor_sku,
            'vendor_price' => $basePrice,
        ];

        return $products;
    }

    /**
     * `Cart::collectTotals()` (and therefore `Cart::validateItems()`, called
     * at the top of checkout's `storeOrder()`) calls this on every cart
     * read/update. Two marketplace-specific concerns live here:
     *
     * 1. The stock implementation unconditionally *recomputes* the item's
     *    price from `getFinalPrice()` (Bagisto's own base/customer-group/
     *    catalog-rule price), which would silently overwrite the vendor's
     *    price set in `prepareForCart()` the very next time the cart page
     *    loaded. Skipped entirely for marketplace items — the vendor's
     *    snapshotted price is authoritative and must survive every reload.
     * 2. The vendor/offer that was valid at add-to-cart time may have gone
     *    inactive, or no longer have enough quantity, by the time checkout
     *    runs. Re-checked here so `Cart::validateItems()`'s existing
     *    "remove inactive items before placing the order" behavior also
     *    covers marketplace offers — no separate checkout validation step
     *    was needed.
     */
    public function validateCartItem(CartItem $item): CartItemValidationResult
    {
        $vendorProductId = $item->additional['marketplace']['vendor_product_id'] ?? null;

        if (! $vendorProductId) {
            return parent::validateCartItem($item);
        }

        $validation = new CartItemValidationResult;

        if ($this->isCartItemInactive($item)) {
            $validation->itemIsInactive();

            return $validation;
        }

        $vendorProduct = VendorProduct::with('vendor')->find($vendorProductId);

        if (
            ! $vendorProduct
            || $vendorProduct->status !== VendorProductStatus::ACTIVE
            || ! $vendorProduct->vendor
            || $vendorProduct->vendor->status !== VendorStatus::ACTIVE
            || $item->quantity > $vendorProduct->quantity
        ) {
            $validation->itemIsInactive();
        }

        return $validation;
    }
}

