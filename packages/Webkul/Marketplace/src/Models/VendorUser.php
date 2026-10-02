<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\Customer;
use Webkul\Marketplace\Contracts\VendorUser as VendorUserContract;
use Webkul\Marketplace\Enums\VendorUserRole;
use Webkul\Marketplace\Enums\VendorUserStatus;

class VendorUser extends Model implements VendorUserContract
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'marketplace_vendor_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'vendor_id',
        'customer_id',
        'role',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'role' => VendorUserRole::class,
        'status' => VendorUserStatus::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Deliberately the stock Bagisto Customer model, not B2B Suite's subclass —
     * the Marketplace package has no dependency on B2B Suite (see architecture doc).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isManagerial(): bool
    {
        return in_array($this->role, VendorUserRole::managerialRoles(), true);
    }
}
