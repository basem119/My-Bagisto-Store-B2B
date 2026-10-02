<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a platform admin suspends an active vendor (e.g. to later deactivate
 * its live offers/storefront presence).
 */
class VendorSuspended
{
    public function __construct(public Vendor $vendor) {}
}
