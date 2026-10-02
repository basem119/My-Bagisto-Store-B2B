<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a platform admin rejects a pending vendor application.
 */
class VendorRejected
{
    public function __construct(public Vendor $vendor) {}
}
