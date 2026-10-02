<?php

namespace Webkul\Marketplace\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Marketplace\Models\VendorProduct;

class VendorProductRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return VendorProduct::class;
    }
}
