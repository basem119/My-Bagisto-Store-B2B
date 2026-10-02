<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Http\Requests\AddVendorMemberRequest;
use Webkul\Marketplace\Http\Requests\UpdateVendorMemberRoleRequest;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Services\VendorMembershipService;

/**
 * Vendor-scoped staff management. Every action re-checks the policy itself
 * (not just the EnsureVendorContext membership check), since adding/removing/
 * re-roling staff requires the stricter `manageMembers` (owner/admin) ability,
 * while plain `view` (any active member) is enough to just list the team.
 */
class TeamController extends Controller
{
    public function __construct(
        protected VendorMembershipService $membershipService,
        protected CustomerRepository $customerRepository,
    ) {}

    public function index(Vendor $vendor): View
    {
        $this->authorize('view', $vendor);

        return view('marketplace::vendor.team.index', [
            'vendor' => $vendor,
            'members' => $this->membershipService->listMembers($vendor),
        ]);
    }

    public function store(AddVendorMemberRequest $request, Vendor $vendor)
    {
        $this->authorize('manageMembers', $vendor);

        $customer = $this->customerRepository->findOneWhere(['email' => $request->validated('email')]);

        $this->membershipService->addMember($vendor, $customer->id, VendorUserRole::from($request->validated('role')));

        return redirect()
            ->route('marketplace.vendor.team.index', $vendor->slug)
            ->with('success', 'Team member added.');
    }

    public function update(UpdateVendorMemberRoleRequest $request, Vendor $vendor, int $customer)
    {
        $this->authorize('manageMembers', $vendor);

        $this->membershipService->assignRole($vendor, $customer, VendorUserRole::from($request->validated('role')));

        return redirect()
            ->route('marketplace.vendor.team.index', $vendor->slug)
            ->with('success', 'Member role updated.');
    }

    public function destroy(Vendor $vendor, int $customer)
    {
        $this->authorize('manageMembers', $vendor);

        $this->membershipService->removeMember($vendor, $customer);

        return redirect()
            ->route('marketplace.vendor.team.index', $vendor->slug)
            ->with('success', 'Member removed.');
    }
}
