<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\PaymentPlan as PaymentPlanContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Sales\Models\OrderProxy;

class PaymentPlan extends Model implements PaymentPlanContract
{
    protected $table = 'marketplace_payment_plans';

    protected $fillable = [
        'order_id',
        'company_id',
        'requested_term_days',
        'approved_term_days',
        'due_date',
        'currency',
        'total_amount',
        'status',
    ];

    protected $casts = [
        'currency'     => FinancialCurrency::class,
        'due_date'     => 'date',
        'total_amount' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PaymentInstallmentProxy::modelClass(), 'payment_plan_id')->orderBy('installment_number');
    }
}
