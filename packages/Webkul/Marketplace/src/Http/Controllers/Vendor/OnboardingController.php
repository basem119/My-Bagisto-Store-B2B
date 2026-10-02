<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Marketplace\Http\Requests\VendorApplicationRequest;
use Webkul\Marketplace\Repositories\VendorUserRepository;
use Webkul\Marketplace\Services\VendorService;

/**
 * Vendor application ("become a vendor") — the Vendor record created here
 * starts at `pending`; no separate application model exists (see
 * docs/architecture/marketplace-domain.md).
 */
class OnboardingController extends Controller
{
    public function __construct(
        protected VendorService $vendorService,
        protected VendorUserRepository $vendorUserRepository,
    ) {}

    public function create(): View
    {
        return view('marketplace::vendor.onboarding.create');
    }

    public function store(VendorApplicationRequest $request)
    {
        $customer = auth()->guard('customer')->user();

        $vendor = $this->vendorService->apply($request->validated(), $customer->id);

        return redirect()
            ->route('marketplace.vendor.dashboard', $vendor->slug)
            ->with('success', 'Your vendor application has been submitted and is pending review.');
    }
}
