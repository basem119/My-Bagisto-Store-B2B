<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;
use Webkul\Marketplace\Events\VendorApproved;
use Webkul\Marketplace\Events\VendorCreated;
use Webkul\Marketplace\Events\VendorRejected;
use Webkul\Marketplace\Events\VendorSuspended;
use Webkul\Marketplace\Exceptions\InvalidVendorStatusTransitionException;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorStatusHistory;
use Webkul\Marketplace\Repositories\VendorRepository;
use Webkul\Marketplace\Repositories\VendorUserRepository;

/**
 * Vendor lifecycle operations (registration + status transitions). Deliberately
 * does not contain vendor-offer or membership-management logic — see
 * VendorMembershipService / VendorProductService.
 */
class VendorService
{
    public function __construct(
        protected VendorRepository $vendorRepository,
        protected VendorUserRepository $vendorUserRepository,
    ) {}

    /**
     * Register a new vendor and make the given customer its owner. Always starts
     * in the `pending` status — never created pre-approved.
     */
    public function register(array $data, int $ownerCustomerId): Vendor
    {
        return DB::transaction(function () use ($data, $ownerCustomerId) {
            $vendor = $this->vendorRepository->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::random(6),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => VendorStatus::PENDING->value,
                'description' => $data['description'] ?? null,
            ]);

            $this->vendorUserRepository->create([
                'vendor_id' => $vendor->id,
                'customer_id' => $ownerCustomerId,
                'role' => VendorUserRole::OWNER->value,
                'status' => VendorUserStatus::ACTIVE->value,
            ]);

            $this->recordStatusChange($vendor, null, VendorStatus::PENDING);

            event(new VendorCreated($vendor));

            return $vendor->refresh();
        });
    }

    /**
     * Approve a pending (or previously suspended/inactive) vendor.
     */
    public function approve(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $this->guardTransition($vendor, [
            VendorStatus::PENDING,
            VendorStatus::SUSPENDED,
            VendorStatus::INACTIVE,
        ], VendorStatus::ACTIVE);

        $from = $vendor->status;

        $vendor->update([
            'status' => VendorStatus::ACTIVE->value,
            'approved_at' => now(),
            'suspended_at' => null,
        ]);

        $this->recordStatusChange($vendor, $from, VendorStatus::ACTIVE, $adminId, $note);

        event(new VendorApproved($vendor));

        return $vendor->refresh();
    }

    /**
     * Suspend an active vendor.
     */
    public function suspend(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $this->guardTransition($vendor, [VendorStatus::ACTIVE], VendorStatus::SUSPENDED);

        $from = $vendor->status;

        $vendor->update([
            'status' => VendorStatus::SUSPENDED->value,
            'suspended_at' => now(),
        ]);

        $this->recordStatusChange($vendor, $from, VendorStatus::SUSPENDED, $adminId, $note);

        event(new VendorSuspended($vendor));

        return $vendor->refresh();
    }

    /**
     * Reject a pending vendor application.
     */
    public function reject(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $this->guardTransition($vendor, [VendorStatus::PENDING], VendorStatus::REJECTED);

        $from = $vendor->status;

        $vendor->update(['status' => VendorStatus::REJECTED->value]);

        $this->recordStatusChange($vendor, $from, VendorStatus::REJECTED, $adminId, $note);

        event(new VendorRejected($vendor));

        return $vendor->refresh();
    }

    /**
     * @param  VendorStatus[]  $allowedFrom
     */
    protected function guardTransition(Vendor $vendor, array $allowedFrom, VendorStatus $to): void
    {
        if (! in_array($vendor->status, $allowedFrom, true)) {
            throw new InvalidVendorStatusTransitionException(
                "Cannot transition vendor #{$vendor->id} from {$vendor->status->value} to {$to->value}."
            );
        }
    }

    protected function recordStatusChange(
        Vendor $vendor,
        ?VendorStatus $from,
        VendorStatus $to,
        ?int $adminId = null,
        ?string $note = null
    ): VendorStatusHistory {
        return VendorStatusHistory::create([
            'vendor_id' => $vendor->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_admin_id' => $adminId,
            'note' => $note,
        ]);
    }
}
