<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Marketplace\Contracts\PaymentInstallment as PaymentInstallmentContract;
use Webkul\Marketplace\Enums\FinancialCurrency;

class PaymentInstallment extends Model implements PaymentInstallmentContract
{
    protected $table = 'marketplace_payment_installments';

    protected $fillable = [
        'payment_plan_id',
        'installment_number',
        'currency',
        'amount',
        'paid_amount',
        'due_date',
        'status',
    ];

    protected $casts = [
        'currency'    => FinancialCurrency::class,
        'amount'      => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'due_date'    => 'date',
    ];

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanProxy::modelClass(), 'payment_plan_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocationProxy::modelClass(), 'installment_id');
    }
}
