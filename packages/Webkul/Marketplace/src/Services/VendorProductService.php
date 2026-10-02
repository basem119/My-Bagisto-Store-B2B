<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\Enums\VendorProductStatus;
use Webkul\Marketplace\Events\VendorProductAttached;
use Webkul\Marketplace\Events\VendorProductDetached;
use Webkul\Marketplace\Exceptions\VendorMembershipException;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Marketplace\Repositories\VendorProductRepository;

/**
 * Vendor catalog/offer operations. A `VendorProduct` row only ever stores the
 * facts that are genuinely vendor-owned (price, quantity, vendor SKU, offer
 * status) — it never duplicates Product attributes/media/SEO.
 */
class VendorProductService
{
    public function __construct(
        protected VendorProductRepository $vendorProductRepository,
    ) {}

    /**
     * Create (or fail if one already exists) a vendor's offer for a product.
     */
    public function attachProduct(Vendor $vendor, int $productId, array $data = []): VendorProduct
    {
        if ($this->vendorProductRepository->findOneWhere(['vendor_id' => $vendor->id, 'product_id' => $productId])) {
            throw new VendorMembershipException(
                "Vendor #{$vendor->id} already has an offer for product #{$productId}."
            );
        }

        return DB::transaction(function () use ($vendor, $productId, $data) {
            $vendorProduct = $this->vendorProductRepository->create([
                'vendor_id' => $vendor->id,
                'product_id' => $productId,
                'vendor_sku' => $data['vendor_sku'] ?? null,
                'price' => $data['price'] ?? null,
                'quantity' => $data['quantity'] ?? 0,
                'status' => $data['status'] ?? VendorProductStatus::DRAFT->value,
            ]);

            event(new VendorProductAttached($vendorProduct));

            return $vendorProduct;
        });
    }

    /**
     * Remove a vendor's offer for a product (does not touch the Product itself).
     */
    public function detachProduct(Vendor $vendor, int $productId): void
    {
        $vendorProduct = $this->vendorProductRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'product_id' => $productId,
        ]);

        if (! $vendorProduct) {
            throw new VendorMembershipException(
                "Vendor #{$vendor->id} has no offer for product #{$productId}."
            );
        }

        $vendorProduct->delete();

        event(new VendorProductDetached($vendor, $productId));
    }

    /**
     * Update the vendor-owned fields of an existing offer (price/quantity/status/SKU).
     */
    public function updateOffer(VendorProduct $vendorProduct, array $data): VendorProduct
    {
        $vendorProduct->update(array_intersect_key($data, array_flip([
            'vendor_sku', 'price', 'quantity', 'status',
        ])));

        return $vendorProduct->refresh();
    }
}
