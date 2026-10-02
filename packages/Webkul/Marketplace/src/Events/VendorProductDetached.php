<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a vendor removes/delists an offer. Carries the vendor + product id
 * (the VendorProduct row itself is deleted by the time this fires).
 */
class VendorProductDetached
{
    public function __construct(public Vendor $vendor, public int $productId) {}
}
