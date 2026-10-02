<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a suspended (or inactive) vendor is reactivated — distinct from
 * VendorApproved, which only applies to the initial pending->active transition.
 */
class VendorReactivated
{
    public function __construct(public Vendor $vendor) {}
}
