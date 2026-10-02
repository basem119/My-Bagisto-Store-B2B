<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\VendorUser;

/**
 * Fired when an existing member's role is changed (not on initial add — see
 * VendorMemberAdded for that).
 */
class VendorRoleChanged
{
    public function __construct(public VendorUser $vendorUser, public string $previousRole) {}
}
