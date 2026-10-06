<?php

namespace Webkul\Marketplace\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Marketplace\Http\Requests\VendorStatusActionRequest;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Repositories\VendorRepository;
use Webkul\Marketplace\Services\VendorMembershipService;
use Webkul\Marketplace\Services\VendorService;

/**
 * Platform-admin vendor lifecycle management. All status changes go through
 * VendorService — this controller never writes `vendors.status` directly.
 */
class VendorController extends Controller
{
    public function __construct(
        protected VendorRepository $vendorRepository,
        protected VendorService $vendorService,
        protected VendorMembershipService $membershipService,
    ) {}

    public function index(): View
    {
        return view('marketplace::admin.vendors.index', [
            'vendors' => $this->vendorRepository->paginate(20),
        ]);
    }

    public function view(Vendor $vendor): View
    {
        return view('marketplace::admin.vendors.view', [
            'vendor' => $vendor,
            'members' => $this->membershipService->listMembers($vendor),
            'statusHistories' => $vendor->statusHistories()->latest()->get(),
        ]);
    }

    public function approve(VendorStatusActionRequest $request, Vendor $vendor)
    {
        $this->vendorService->approve($vendor, $this->currentAdminId(), $request->validated('note'));

        return redirect()->route('marketplace.admin.vendors.view', $vendor->id)->with('success', 'Vendor approved.');
    }

    public function reject(VendorStatusActionRequest $request, Vendor $vendor)
    {
        $this->vendorService->reject($vendor, $this->currentAdminId(), $request->validated('note'));

        return redirect()->route('marketplace.admin.vendors.view', $vendor->id)->with('success', 'Vendor rejected.');
    }

    public function suspend(VendorStatusActionRequest $request, Vendor $vendor)
    {
        $this->vendorService->suspend($vendor, $this->currentAdminId(), $request->validated('note'));

        return redirect()->route('marketplace.admin.vendors.view', $vendor->id)->with('success', 'Vendor suspended.');
    }

    public function reactivate(VendorStatusActionRequest $request, Vendor $vendor)
    {
        $this->vendorService->reactivate($vendor, $this->currentAdminId(), $request->validated('note'));

        return redirect()->route('marketplace.admin.vendors.view', $vendor->id)->with('success', 'Vendor reactivated.');
    }

    public function updateCommissionRate(Request $request, Vendor $vendor)
    {
        $data = $request->validate([
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $vendor->update([
            'commission_rate' => $data['commission_rate'] ?? null,
        ]);

        return redirect()->route('marketplace.admin.vendors.view', $vendor->id)
            ->with('success', trans('marketplace::app.admin.vendors.view.commission-rate-updated'));
    }

    protected function currentAdminId(): ?int
    {
        return auth()->guard('admin')->id();
    }
}
