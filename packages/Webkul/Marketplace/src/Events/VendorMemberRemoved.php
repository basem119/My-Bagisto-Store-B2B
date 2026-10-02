<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a vendor team member is removed. Carries the vendor + the removed
 * customer id (the VendorUser row itself is deleted by the time this fires).
 */
class VendorMemberRemoved
{
    public function __construct(public Vendor $vendor, public int $customerId) {}
}
