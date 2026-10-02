<?php

namespace Webkul\Marketplace\Policies;

use Webkul\Customer\Models\Customer;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorUser;

/**
 * Every check is parameterized by a specific Vendor instance — membership in
 * Vendor A never grants any permission on Vendor B. All checks additionally
 * require the membership itself to be `active` — a suspended/invited member
 * cannot perform any vendor operation (see docs/architecture/marketplace-domain.md).
 */
class VendorPolicy
{
    /**
     * May view the vendor's own dashboard/profile (any active membership role).
     */
    public function view(Customer $customer, Vendor $vendor): bool
    {
        return $this->activeMembershipFor($customer, $vendor) !== null;
    }

    /**
     * May update vendor profile/settings (owner/admin only).
     */
    public function update(Customer $customer, Vendor $vendor): bool
    {
        $membership = $this->activeMembershipFor($customer, $vendor);

        return $membership !== null && in_array($membership->role, VendorUserRole::managerialRoles(), true);
    }

    /**
     * May add/remove/re-role vendor staff (owner/admin only).
     */
    public function manageMembers(Customer $customer, Vendor $vendor): bool
    {
        return $this->update($customer, $vendor);
    }

    /**
     * May create/update/delete this vendor's product offers (any active member).
     */
    public function manageProducts(Customer $customer, Vendor $vendor): bool
    {
        return $this->activeMembershipFor($customer, $vendor) !== null;
    }

    protected function activeMembershipFor(Customer $customer, Vendor $vendor): ?VendorUser
    {
        $vendorUser = $vendor->users()->where('customer_id', $customer->id)->first();

        if (! $vendorUser || $vendorUser->status !== VendorUserStatus::ACTIVE) {
            return null;
        }

        return $vendorUser;
    }
}
