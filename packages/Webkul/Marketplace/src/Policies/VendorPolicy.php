<?php

namespace Webkul\Marketplace\Policies;

use Webkul\Customer\Models\Customer;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Models\Vendor;

/**
 * Every check is parameterized by a specific Vendor instance — membership in
 * Vendor A never grants any permission on Vendor B.
 */
class VendorPolicy
{
    /**
     * May view the vendor's own dashboard/profile (any membership role).
     */
    public function view(Customer $customer, Vendor $vendor): bool
    {
        return $this->roleFor($customer, $vendor) !== null;
    }

    /**
     * May update vendor profile/settings (owner/admin only).
     */
    public function update(Customer $customer, Vendor $vendor): bool
    {
        return in_array($this->roleFor($customer, $vendor), VendorUserRole::managerialRoles(), true);
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
        return $this->roleFor($customer, $vendor) !== null;
    }

    protected function roleFor(Customer $customer, Vendor $vendor): ?VendorUserRole
    {
        $vendorUser = $vendor->users()->where('customer_id', $customer->id)->first();

        return $vendorUser?->role;
    }
}
