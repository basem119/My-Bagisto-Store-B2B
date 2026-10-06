<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\CompanyPaymentPolicy as CompanyPaymentPolicyContract;

class CompanyPaymentPolicy extends Model implements CompanyPaymentPolicyContract
{
    protected $table = 'marketplace_company_payment_policies';

    protected $fillable = [
        'company_id',
        'maximum_term_days',
        'installments_allowed',
        'maximum_installments',
        'is_active',
    ];

    protected $casts = [
        'installments_allowed' => 'boolean',
        'is_active'            => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }
}
