<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a new vendor is registered (status: pending).
 */
class VendorCreated
{
    public function __construct(public Vendor $vendor) {}
}
