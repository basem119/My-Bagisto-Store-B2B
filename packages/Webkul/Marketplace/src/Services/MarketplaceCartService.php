<?php

namespace Webkul\Marketplace\Services;

use Webkul\Checkout\Facades\Cart;
use Webkul\Marketplace\Enums\VendorProductStatus;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Exceptions\VendorOfferValidationException;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Product\Repositories\ProductRepository;

/**
 * The marketplace-facing add-to-cart entry point. Resolves and validates a
 * VendorProduct offer server-side, then delegates the actual cart
 * persistence to Bagisto's own `Cart` facade/service — this is NOT a second
 * cart implementation. See `Webkul\Marketplace\Type\MarketplaceAwareSimple`
 * for how the vendor's price/identity survive `Cart::addProduct()`.
 */
class MarketplaceCartService
{
    public function __construct(
        protected ProductRepository $productRepository,
    ) {}

    /**
     * @return \Webkul\Checkout\Contracts\Cart
     */
    public function addOfferToCart(int $vendorProductId, int $productId, int $quantity)
    {
        $vendorProduct = $this->resolveEligibleOffer($vendorProductId, $productId);

        $this->assertSufficientQuantity($vendorProduct, $quantity);

        $product = $this->productRepository->findOrFail($vendorProduct->product_id);

        $cart = Cart::addProduct($product, [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'vendor_product_id' => $vendorProduct->id,
            'vendor_id' => $vendorProduct->vendor_id,
        ]);

        if (is_string($cart)) {
            throw new VendorOfferValidationException($cart);
        }

        return $cart;
    }

    /**
     * Never trusts a submitted price, vendor_id, or an unvalidated
     * product/vendor-product pairing — everything is re-derived from the
     * database here.
     */
    public function resolveEligibleOffer(int $vendorProductId, ?int $expectedProductId = null): VendorProduct
    {
        $vendorProduct = VendorProduct::with('vendor')->find($vendorProductId);

        if (! $vendorProduct) {
            throw new VendorOfferValidationException('This vendor offer no longer exists.');
        }

        if (
            $expectedProductId !== null
            && $vendorProduct->product_id !== $expectedProductId
        ) {
            throw new VendorOfferValidationException('This vendor offer does not belong to the requested product.');
        }

        if ($vendorProduct->status !== VendorProductStatus::ACTIVE) {
            throw new VendorOfferValidationException('This vendor offer is not currently available.');
        }

        if (
            ! $vendorProduct->vendor
            || $vendorProduct->vendor->status !== VendorStatus::ACTIVE
        ) {
            throw new VendorOfferValidationException('This vendor is not currently active.');
        }

        return $vendorProduct;
    }

    /**
     * Requested quantity + whatever of this SAME vendor offer is already in
     * the cart must not exceed `VendorProduct.quantity`.
     */
    public function assertSufficientQuantity(VendorProduct $vendorProduct, int $requestedQuantity): void
    {
        if ($requestedQuantity < 1) {
            throw new VendorOfferValidationException('Quantity must be at least 1.');
        }

        $alreadyInCart = $this->quantityAlreadyInCart($vendorProduct->id);

        if ($alreadyInCart + $requestedQuantity > $vendorProduct->quantity) {
            throw new VendorOfferValidationException('The requested quantity exceeds what this vendor currently has available.');
        }
    }

    protected function quantityAlreadyInCart(int $vendorProductId): int
    {
        $cart = Cart::getCart();

        if (! $cart) {
            return 0;
        }

        return (int) $cart->all_items
            ->filter(fn ($item) => ($item->additional['vendor_product_id'] ?? null) == $vendorProductId)
            ->sum('quantity');
    }
}
