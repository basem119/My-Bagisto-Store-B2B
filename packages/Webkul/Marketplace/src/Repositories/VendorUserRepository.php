<?php

namespace Webkul\Marketplace\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Marketplace\Models\VendorUser;

class VendorUserRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return VendorUser::class;
    }
}
