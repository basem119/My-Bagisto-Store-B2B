<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;
use Webkul\Marketplace\Events\VendorMemberAdded;
use Webkul\Marketplace\Events\VendorMemberRemoved;
use Webkul\Marketplace\Events\VendorRoleChanged;
use Webkul\Marketplace\Exceptions\VendorMembershipException;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorUser;
use Webkul\Marketplace\Repositories\VendorUserRepository;

/**
 * Vendor staff/membership operations. Every method is parameterized by a specific
 * Vendor instance — there is no "global vendor admin" concept (see VendorPolicy).
 *
 * Ownership transfer is explicitly deferred (see docs/architecture/marketplace-domain.md):
 * a vendor's single `owner` is set once at application time (VendorService::apply())
 * and can never be reassigned here — `addMember()`/`assignRole()` both reject the
 * `owner` role outright, which also structurally prevents staff from ever
 * self-escalating to owner.
 */
class VendorMembershipService
{
    public function __construct(
        protected VendorUserRepository $vendorUserRepository,
    ) {}

    /**
     * Add a customer to a vendor's team. Fails if the customer already belongs to
     * this vendor (unique constraint also enforces this at the DB level), or if
     * `$role` is `owner` (ownership transfer is deferred — see class docblock).
     */
    public function addMember(Vendor $vendor, int $customerId, VendorUserRole $role): VendorUser
    {
        $this->assertNotOwnerRole($role);

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

        $this->assertNotLastOwner($vendor, $vendorUser);

        $vendorUser->delete();

        event(new VendorMemberRemoved($vendor, $customerId));
    }

    /**
     * Change an existing member's role. Rejects assigning `owner` (ownership
     * transfer is deferred) and rejects demoting the last remaining owner.
     */
    public function assignRole(Vendor $vendor, int $customerId, VendorUserRole $role): VendorUser
    {
        $this->assertNotOwnerRole($role);

        $vendorUser = $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]);

        if (! $vendorUser) {
            throw new VendorMembershipException(
                "Customer #{$customerId} is not a member of vendor #{$vendor->id}."
            );
        }

        $this->assertNotLastOwner($vendor, $vendorUser);

        $previousRole = $vendorUser->role->value;

        $vendorUser->update(['role' => $role->value]);
        $vendorUser->refresh();

        event(new VendorRoleChanged($vendorUser, $previousRole));

        return $vendorUser;
    }

    /**
     * List a vendor's team members (for the Team / staff management screen).
     */
    public function listMembers(Vendor $vendor): Collection
    {
        return $vendor->users()->with('customer')->get();
    }

    /**
     * Whether the given customer is a member of the given vendor (any role,
     * any status — see isActiveMember() for the operational check).
     */
    public function isMember(Vendor $vendor, int $customerId): bool
    {
        return $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]) !== null;
    }

    /**
     * Whether the given customer is a member AND that membership is active —
     * suspended/invited members must not be able to perform vendor operations.
     */
    public function isActiveMember(Vendor $vendor, int $customerId): bool
    {
        $vendorUser = $this->vendorUserRepository->findOneWhere([
            'vendor_id' => $vendor->id,
            'customer_id' => $customerId,
        ]);

        return $vendorUser !== null && $vendorUser->status === VendorUserStatus::ACTIVE;
    }

    protected function assertNotOwnerRole(VendorUserRole $role): void
    {
        if ($role === VendorUserRole::OWNER) {
            throw new VendorMembershipException(
                'Ownership cannot be assigned here — ownership transfer is not supported in this phase.'
            );
        }
    }

    protected function assertNotLastOwner(Vendor $vendor, VendorUser $vendorUser): void
    {
        if (
            $vendorUser->role === VendorUserRole::OWNER
            && $vendor->users()->where('role', VendorUserRole::OWNER->value)->count() <= 1
        ) {
            throw new VendorMembershipException(
                "Vendor #{$vendor->id} must retain at least one owner."
            );
        }
    }
}
