<?php

namespace Webkul\Marketplace\Enums;

/**
 * Membership-row status, distinct from the vendor's own lifecycle status.
 */
enum VendorUserStatus: string
{
    case INVITED = 'invited';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}
