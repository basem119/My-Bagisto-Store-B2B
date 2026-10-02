<?php

namespace Webkul\Marketplace\Http\Controllers\Admin;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Marketplace\Repositories\VendorProductRepository;

/**
 * Oversight-only: inspect vendor offers across all vendors. No mutation —
 * commission/settlement/payout/order management are later phases.
 */
class VendorProductController extends Controller
{
    public function __construct(
        protected VendorProductRepository $vendorProductRepository,
    ) {}

    public function index(): View
    {
        return view('marketplace::admin.vendor-products.index', [
            'offers' => $this->vendorProductRepository
                ->with(['vendor', 'product'])
                ->scopeQuery(fn ($query) => $query->latest())
                ->paginate(20),
        ]);
    }
}
