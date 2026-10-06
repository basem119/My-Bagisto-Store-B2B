<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Marketplace\Contracts\PaymentAllocation as PaymentAllocationContract;
use Webkul\Marketplace\Enums\FinancialCurrency;

class PaymentAllocation extends Model implements PaymentAllocationContract
{
    protected $table = 'marketplace_payment_allocations';

    protected $fillable = [
        'payment_id',
        'installment_id',
        'currency',
        'amount',
    ];

    protected $casts = [
        'currency' => FinancialCurrency::class,
        'amount'   => 'decimal:4',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentProxy::modelClass(), 'payment_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PaymentInstallmentProxy::modelClass(), 'installment_id');
    }
}
