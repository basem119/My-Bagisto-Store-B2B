<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\VendorUser;

/**
 * Fired when a customer is added as a vendor team member (e.g. for a future
 * "you were added to vendor X" notification).
 */
class VendorMemberAdded
{
    public function __construct(public VendorUser $vendorUser) {}
}
