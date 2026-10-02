<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;
use Webkul\Marketplace\Events\VendorMemberAdded;
use Webkul\Marketplace\Events\VendorMemberRemoved;
use Webkul\Marketplace\Exceptions\VendorMembershipException;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorUser;
use Webkul\Marketplace\Repositories\VendorUserRepository;

/**
 * Vendor staff/membership operations. Every method is parameterized by a specific
 * Vendor instance — there is no "global vendor admin" concept (see VendorPolicy).
 */
class VendorMembershipService
{
    public function __construct(
        protected VendorUserRepository $vendorUserRepository,
    ) {}

    /**
     * Add a customer to a vendor's team. Fails if the customer already belongs to
     * this vendor (unique constraint also enforces this at the DB level).
     */
    public function addMember(Vendor $vendor, int $customerId, VendorUserRole $role): VendorUser
    {
        if ($this->vendorUserRepository->findOneWhere(['vendor_id' => $vendor->id, 'customer_id' => $customerId])) {
            throw new VendorMembershipException(
                "Customer #{$customerId} is already a member of vendor #{$vendor->id}."
            );
        }

        return DB::transaction(function () use ($vendor, $customerId, $role) {
            $vendorUser = $this->vendorUserRepository->create([
                'vendor_id' => $vendor->id,
                'customer_id' => $customerId,
                'role' => $role->value,
                'status' => VendorUserStatus::ACTIVE->value,
            ]);

            event(new VendorMemberAdded($vendorUser));

            return $vendorUser;
        });
    }

    /**
     * Remove a customer from a vendor's team. The vendor's last `owner` cannot be
     * removed — a vendor must always retain at least one owner.
     */
    public function removeMember(Vendor $vendor, int $customerId): void
    {
        $vendorUser = $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]);

        if (! $vendorUser) {
            throw new VendorMembershipException(
                "Customer #{$customerId} is not a member of vendor #{$vendor->id}."
            );
        }

        if (
            $vendorUser->role === VendorUserRole::OWNER
            && $vendor->users()->where('role', VendorUserRole::OWNER->value)->count() <= 1
        ) {
            throw new VendorMembershipException(
                "Vendor #{$vendor->id} must retain at least one owner."
            );
        }

        $vendorUser->delete();

        event(new VendorMemberRemoved($vendor, $customerId));
    }

    /**
     * Change an existing member's role.
     */
    public function assignRole(Vendor $vendor, int $customerId, VendorUserRole $role): VendorUser
    {
        $vendorUser = $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]);

        if (! $vendorUser) {
            throw new VendorMembershipException(
                "Customer #{$customerId} is not a member of vendor #{$vendor->id}."
            );
        }

        $vendorUser->update(['role' => $role->value]);

        return $vendorUser->refresh();
    }

    /**
     * Whether the given customer is a member of the given vendor (any role).
     */
    public function isMember(Vendor $vendor, int $customerId): bool
    {
        return $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]) !== null;
    }
}
