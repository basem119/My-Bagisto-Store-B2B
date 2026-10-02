<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Webkul\Shop\Http\Controllers\Controller as ShopController;

/**
 * Base controller for Vendor Portal controllers — adds policy authorization
 * support ($this->authorize()) on top of Shop's base controller, which
 * doesn't include it by default.
 */
abstract class Controller extends ShopController
{
    use AuthorizesRequests;
}
