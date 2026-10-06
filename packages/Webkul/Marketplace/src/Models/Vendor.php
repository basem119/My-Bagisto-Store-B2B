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
        'commission_rate',
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
        'commission_rate' => 'decimal:4',
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

    /**
     * Konekt Concord auto-registers an explicit route-model binder for every
     * concord model keyed by its short name (here "vendor"), and that
     * explicit binder always calls `resolveRouteBinding($value)` WITHOUT a
     * field — it never sees the `{vendor:slug}` binding field declared on
     * the vendor-portal routes. Disambiguate by shape instead: numeric
     * values are the vendor's primary key (used by /admin/marketplace
     * routes), everything else is the public slug (used by /vendor routes).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->first();
        }

        return is_numeric($value)
            ? $this->where($this->getKeyName(), $value)->first()
            : $this->where('slug', $value)->first();
    }
}
