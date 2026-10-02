<?php

namespace Webkul\Marketplace\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Marketplace\Models\Vendor;

class VendorRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Vendor::class;
    }
}
