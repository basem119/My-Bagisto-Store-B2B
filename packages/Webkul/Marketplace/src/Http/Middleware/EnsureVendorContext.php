<?php

namespace Webkul\Marketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Services\VendorMembershipService;

/**
 * Verifies the authenticated customer is an ACTIVE member of the `{vendor}`
 * resolved by Laravel's own route-model-binding (`{vendor:slug}` — see
 * vendor-routes.php) — never trusts the URL alone. This is the only place a
 * request is allowed to establish "which vendor am I acting as".
 */
class EnsureVendorContext
{
    public function __construct(
        protected VendorMembershipService $membershipService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! auth()->guard('customer')->check()) {
            return redirect()->route('shop.customer.session.index');
        }

        $vendor = $request->route('vendor');

        if (! $vendor instanceof Vendor) {
            abort(404);
        }

        $customer = auth()->guard('customer')->user();

        if (! $this->membershipService->isActiveMember($vendor, $customer->id)) {
            abort(403, 'You do not have access to this vendor.');
        }

        return $next($request);
    }
}
