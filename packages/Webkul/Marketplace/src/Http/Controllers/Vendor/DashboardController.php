<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Marketplace\Models\Vendor;

/**
 * Minimal vendor dashboard — proves Login -> Vendor context -> Authorization
 * -> Dashboard works. No analytics; see docs/architecture/marketplace-domain.md
 * for what's deferred.
 */
class DashboardController extends Controller
{
    /**
     * `$vendor` arrives here already resolved + membership-verified by
     * EnsureVendorContext — this controller trusts it completely.
     */
    public function index(Vendor $vendor): View
    {
        $customer = auth()->guard('customer')->user();

        $currentMembership = $vendor->users()->where('customer_id', $customer->id)->first();

        return view('marketplace::vendor.dashboard.index', [
            'vendor' => $vendor,
            'currentRole' => $currentMembership->role->label(),
            'memberCount' => $vendor->users()->count(),
            'productCount' => $vendor->products()->count(),
        ]);
    }
}
