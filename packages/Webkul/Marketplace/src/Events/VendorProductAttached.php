<?php

namespace Webkul\Marketplace\Events;

use Webkul\Marketplace\Models\VendorProduct;

/**
 * Fired when a vendor lists an offer against a Bagisto product. A future catalog/
 * search index phase can react to this without coupling into VendorProductService.
 */
class VendorProductAttached
{
    public function __construct(public VendorProduct $vendorProduct) {}
}
