<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Marketplace\Enums\VendorStatus;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;
use Webkul\Marketplace\Events\VendorApproved;
use Webkul\Marketplace\Events\VendorCreated;
use Webkul\Marketplace\Events\VendorReactivated;
use Webkul\Marketplace\Events\VendorRejected;
use Webkul\Marketplace\Events\VendorSuspended;
use Webkul\Marketplace\Exceptions\InvalidVendorStatusTransitionException;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Models\VendorStatusHistory;
use Webkul\Marketplace\Notifications\VendorApplicationReceived;
use Webkul\Marketplace\Notifications\VendorStatusChanged;
use Webkul\Marketplace\Repositories\VendorRepository;
use Webkul\Marketplace\Repositories\VendorUserRepository;
use Webkul\User\Models\Admin;

/**
 * Vendor lifecycle operations (application + status transitions). Deliberately
 * does not contain vendor-offer or membership-management logic — see
 * VendorMembershipService / VendorProductService. This is the ONLY place
 * `vendors.status` is allowed to change.
 */
class VendorService
{
    public function __construct(
        protected VendorRepository $vendorRepository,
        protected VendorUserRepository $vendorUserRepository,
    ) {}

    /**
     * Submit a vendor application and make the given customer its owner.
     * Always starts in the `pending` status — never created pre-approved.
     * This IS the application: no separate "application" record exists (see
     * docs/architecture/marketplace-domain.md).
     */
    public function apply(array $data, int $ownerCustomerId): Vendor
    {
        $vendor = DB::transaction(function () use ($data, $ownerCustomerId) {
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

        // Best-effort notification — a mail failure must never roll back the application.
        try {
            Notification::send(Admin::all(), new VendorApplicationReceived($vendor));
        } catch (\Throwable $e) {
            report($e);
        }

        return $vendor;
    }

    /**
     * Approve a pending vendor application.
     */
    public function approve(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $vendor = $this->transition($vendor, VendorStatus::ACTIVE, $adminId, $note, function (Vendor $vendor) {
            $vendor->update([
                'status' => VendorStatus::ACTIVE->value,
                'approved_at' => now(),
                'suspended_at' => null,
            ]);
        });

        event(new VendorApproved($vendor));
        $this->notifyOwner($vendor, $note);

        return $vendor;
    }

    /**
     * Reject a pending vendor application.
     */
    public function reject(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $vendor = $this->transition($vendor, VendorStatus::REJECTED, $adminId, $note, function (Vendor $vendor) {
            $vendor->update(['status' => VendorStatus::REJECTED->value]);
        });

        event(new VendorRejected($vendor));
        $this->notifyOwner($vendor, $note);

        return $vendor;
    }

    /**
     * Suspend an active vendor.
     */
    public function suspend(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $vendor = $this->transition($vendor, VendorStatus::SUSPENDED, $adminId, $note, function (Vendor $vendor) {
            $vendor->update([
                'status' => VendorStatus::SUSPENDED->value,
                'suspended_at' => now(),
            ]);
        });

        event(new VendorSuspended($vendor));
        $this->notifyOwner($vendor, $note);

        return $vendor;
    }

    /**
     * Reactivate a suspended (or inactive) vendor. Distinct from `approve()`,
     * which only applies to the initial pending application.
     */
    public function reactivate(Vendor $vendor, ?int $adminId = null, ?string $note = null): Vendor
    {
        $vendor = $this->transition($vendor, VendorStatus::ACTIVE, $adminId, $note, function (Vendor $vendor) {
            $vendor->update([
                'status' => VendorStatus::ACTIVE->value,
                'suspended_at' => null,
            ]);
        });

        event(new VendorReactivated($vendor));
        $this->notifyOwner($vendor, $note);

        return $vendor;
    }

    /**
     * Validate + perform a status transition + record history, all in one
     * transaction. `$mutate` performs the actual `update()` call so each
     * public method can set its own extra columns (approved_at, etc.).
     */
    protected function transition(
        Vendor $vendor,
        VendorStatus $to,
        ?int $adminId,
        ?string $note,
        \Closure $mutate
    ): Vendor {
        $this->guardTransition($vendor, $to);

        $from = $vendor->status;

        return DB::transaction(function () use ($vendor, $from, $to, $adminId, $note, $mutate) {
            $mutate($vendor);

            $this->recordStatusChange($vendor, $from, $to, $adminId, $note);

            return $vendor->refresh();
        });
    }

    protected function guardTransition(Vendor $vendor, VendorStatus $to): void
    {
        if (! $vendor->status->canTransitionTo($to)) {
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

    /**
     * Best-effort notification to the vendor's owner — never throws.
     */
    protected function notifyOwner(Vendor $vendor, ?string $note = null): void
    {
        try {
            $owner = $vendor->users()->where('role', VendorUserRole::OWNER->value)->first();

            if (! $owner) {
                return;
            }

            $customer = Customer::find($owner->customer_id);

            if ($customer) {
                $customer->notify(new VendorStatusChanged($vendor, $note));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
