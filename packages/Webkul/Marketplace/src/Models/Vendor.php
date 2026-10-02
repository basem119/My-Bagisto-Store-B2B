<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Marketplace\Contracts\Vendor as VendorContract;
use Webkul\Marketplace\Enums\VendorStatus;

class Vendor extends Model implements VendorContract
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'marketplace_vendors';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'status',
        'description',
        'logo_path',
        'banner_path',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'approved_at',
        'suspended_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status' => VendorStatus::class,
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(VendorUser::class, 'vendor_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VendorProduct::class, 'vendor_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(VendorStatusHistory::class, 'vendor_id');
    }

    public function isActive(): bool
    {
        return $this->status === VendorStatus::ACTIVE;
    }
}
