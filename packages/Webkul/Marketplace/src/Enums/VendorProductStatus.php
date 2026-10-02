<?php

namespace Webkul\Marketplace\Enums;

/**
 * Status of a single vendor offer (vendor_product row), independent of the
 * underlying Product's own status.
 */
enum VendorProductStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
