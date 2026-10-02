<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\Vendor;

/**
 * Fired when a platform admin approves a pending vendor. Future phases (e.g. vendor
 * onboarding email, storefront activation) react to this instead of polling status.
 */
class VendorApproved
{
    public function __construct(public Vendor $vendor) {}
}
