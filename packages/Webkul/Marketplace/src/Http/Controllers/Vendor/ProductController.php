<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Marketplace\Http\Requests\AttachVendorProductRequest;
use Webkul\Marketplace\Http\Requests\UpdateVendorProductRequest;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Marketplace\Repositories\VendorProductRepository;
use Webkul\Marketplace\Services\VendorProductService;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Vendor-scoped offer (VendorProduct) management. `manageProducts` (any
 * active member — see VendorPolicy) gates create/update/delete; `view` gates
 * listing, matching the Team controller's split between read and write.
 */
class ProductController extends Controller
{
    public function __construct(
        protected VendorProductService $vendorProductService,
        protected VendorProductRepository $vendorProductRepository,
        protected ProductRepository $productRepository,
    ) {}

    public function index(Vendor $vendor): View
    {
        $this->authorize('view', $vendor);

        return view('marketplace::vendor.products.index', [
            'vendor' => $vendor,
            'offers' => $this->vendorProductRepository
                ->with('product')
                ->scopeQuery(fn ($query) => $query->where('vendor_id', $vendor->id)->latest())
                ->paginate(20),
        ]);
    }

    public function create(Vendor $vendor): View
    {
        $this->authorize('manageProducts', $vendor);

        return view('marketplace::vendor.products.create', ['vendor' => $vendor]);
    }

    public function store(AttachVendorProductRequest $request, Vendor $vendor)
    {
        $this->authorize('manageProducts', $vendor);

        $product = $this->productRepository->findOneByField('sku', $request->validated('sku'));

        $this->vendorProductService->attachProduct($vendor, $product->id, $request->validated());

        return redirect()
            ->route('marketplace.vendor.products.index', $vendor->slug)
            ->with('success', 'Offer created.');
    }

    public function edit(Vendor $vendor, VendorProduct $vendorProduct): View
    {
        $this->authorize('manageProducts', $vendor);

        $this->assertOwnedByVendor($vendor, $vendorProduct);

        return view('marketplace::vendor.products.edit', [
            'vendor' => $vendor,
            'offer' => $vendorProduct,
        ]);
    }

    public function update(UpdateVendorProductRequest $request, Vendor $vendor, VendorProduct $vendorProduct)
    {
        $this->authorize('manageProducts', $vendor);

        $this->assertOwnedByVendor($vendor, $vendorProduct);

        $this->vendorProductService->updateOffer($vendorProduct, $request->validated());

        return redirect()
            ->route('marketplace.vendor.products.index', $vendor->slug)
            ->with('success', 'Offer updated.');
    }

    public function destroy(Vendor $vendor, VendorProduct $vendorProduct)
    {
        $this->authorize('manageProducts', $vendor);

        $this->assertOwnedByVendor($vendor, $vendorProduct);

        $this->vendorProductService->detachProduct($vendor, $vendorProduct->product_id);

        return redirect()
            ->route('marketplace.vendor.products.index', $vendor->slug)
            ->with('success', 'Offer removed.');
    }

    /**
     * `{vendorProduct}` is bound by bare id, independent of the `{vendor:slug}`
     * in the same URL — without this check, an active member of Vendor A could
     * pass Vendor B's vendor-product id while still inside Vendor A's URL
     * prefix. EnsureVendorContext only proves membership in Vendor A; it says
     * nothing about who owns this specific offer row.
     */
    protected function assertOwnedByVendor(Vendor $vendor, VendorProduct $vendorProduct): void
    {
        abort_unless($vendorProduct->vendor_id === $vendor->id, 404);
    }
}
