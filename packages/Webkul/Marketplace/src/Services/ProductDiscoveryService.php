<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\Enums\VendorProductStatus;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Models\VendorProduct;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Read-only marketplace discovery/storefront layer — composes the existing
 * Bagisto Product, the existing `product_flat` eligibility signals, and
 * Phase 7's VendorProduct offers. Never duplicates Product data; never
 * writes anything. See docs/architecture/marketplace-domain.md for the
 * eligibility rule this enforces and why it deliberately does NOT require
 * `product_flat.visible_individually`.
 */
class ProductDiscoveryService
{
    public function __construct(
        protected ProductRepository $productRepository,
    ) {}

    /**
     * One row per eligible Bagisto product (never one row per vendor offer),
     * with the lowest eligible vendor price and a count of eligible vendors.
     * `$filters` may contain `category_id`, `vendor_id`, `q` (name search).
     */
    public function paginateEligibleProducts(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->eligibleOffersQuery()
            ->select([
                'pf.product_id',
                'pf.name',
                'pf.url_key',
                DB::raw('MIN(vp.price) as from_price'),
                DB::raw('COUNT(DISTINCT vp.vendor_id) as vendor_count'),
            ]);

        if (! empty($filters['category_id'])) {
            $query->join('product_categories as pc', 'pc.product_id', '=', 'vp.product_id')
                ->where('pc.category_id', $filters['category_id']);
        }

        if (! empty($filters['vendor_id'])) {
            $query->where('vp.vendor_id', $filters['vendor_id']);
        }

        if (! empty($filters['q'])) {
            $query->where('pf.name', 'like', '%'.$filters['q'].'%');
        }

        return $query
            ->groupBy('pf.product_id', 'pf.name', 'pf.url_key')
            ->orderBy('pf.name')
            ->paginate($perPage);
    }

    /**
     * Resolves a product by its existing Bagisto `url_key` — only if it is
     * both a real, enabled product AND currently has at least one eligible
     * vendor offer. Returns null for either case (never leaks an otherwise-
     * disabled/offer-less product just because the URL guesses correctly).
     */
    public function findEligibleProductByUrlKey(string $urlKey): ?Product
    {
        $productId = DB::table('product_flat')
            ->where('url_key', $urlKey)
            ->where('channel', core()->getRequestedChannelCode())
            ->where('locale', core()->getRequestedLocaleCode())
            ->where('status', 1)
            ->value('product_id');

        if (! $productId || ! $this->hasEligibleOffer((int) $productId)) {
            return null;
        }

        return $this->productRepository->with(['images', 'categories'])->find($productId);
    }

    /**
     * Eligible vendor offers for a single product's detail page, cheapest
     * first — vendor eager-loaded to avoid N+1 (two queries total, not one
     * per offer).
     */
    public function eligibleOffersFor(Product $product): Collection
    {
        return VendorProduct::query()
            ->with('vendor')
            ->where('product_id', $product->id)
            ->where('status', VendorProductStatus::ACTIVE->value)
            ->where('quantity', '>', 0)
            ->whereHas('vendor', fn ($q) => $q->where('status', VendorStatus::ACTIVE->value))
            ->orderBy('price')
            ->get();
    }

    protected function hasEligibleOffer(int $productId): bool
    {
        return DB::table('marketplace_vendor_products as vp')
            ->join('marketplace_vendors as v', 'v.id', '=', 'vp.vendor_id')
            ->where('vp.product_id', $productId)
            ->where('vp.status', VendorProductStatus::ACTIVE->value)
            ->where('vp.quantity', '>', 0)
            ->where('v.status', VendorStatus::ACTIVE->value)
            ->exists();
    }

    /**
     * Base join shared by listing + eligibility checks: an active-vendor,
     * active-offer, in-stock row against an enabled product_flat row for the
     * current channel/locale. Deliberately does NOT filter on
     * `visible_individually` — see class docblock.
     */
    protected function eligibleOffersQuery()
    {
        return DB::table('marketplace_vendor_products as vp')
            ->join('marketplace_vendors as v', 'v.id', '=', 'vp.vendor_id')
            ->join('product_flat as pf', function ($join) {
                $join->on('pf.product_id', '=', 'vp.product_id')
                    ->where('pf.channel', core()->getRequestedChannelCode())
                    ->where('pf.locale', core()->getRequestedLocaleCode());
            })
            ->where('vp.status', VendorProductStatus::ACTIVE->value)
            ->where('vp.quantity', '>', 0)
            ->where('v.status', VendorStatus::ACTIVE->value)
            ->where('pf.status', 1);
    }
}
