<?php

namespace Webkul\Marketplace\Http\Controllers\Shop;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Marketplace\Services\ProductDiscoveryService;
use Webkul\Shop\Http\Controllers\Controller;

/**
 * Public, read-only marketplace discovery. No auth, no policy — anyone can
 * browse (mirrors Bagisto's own public product/category browsing). Vendors
 * keep managing offers exclusively through the Phase 7 vendor portal; this
 * controller never writes anything.
 */
class MarketplaceController extends Controller
{
    public function __construct(
        protected ProductDiscoveryService $discoveryService,
    ) {}

    public function index(Request $request): View
    {
        $products = $this->discoveryService->paginateEligibleProducts([
            'category_id' => $request->query('category_id'),
            'vendor_id' => $request->query('vendor_id'),
            'q' => $request->query('q'),
        ]);

        return view('marketplace::shop.index', [
            'products' => $products,
        ]);
    }

    public function show(string $urlKey): View
    {
        $product = $this->discoveryService->findEligibleProductByUrlKey($urlKey);

        abort_if(! $product, 404);

        return view('marketplace::shop.show', [
            'product' => $product,
            'offers' => $this->discoveryService->eligibleOffersFor($product),
        ]);
    }
}
