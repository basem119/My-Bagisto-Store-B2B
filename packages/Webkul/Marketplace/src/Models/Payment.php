<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\Payment as PaymentContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\User\Models\AdminProxy;

class Payment extends Model implements PaymentContract
{
    protected $table = 'marketplace_payments';

    protected $fillable = [
        'company_id',
        'currency',
        'amount',
        'allocated_amount',
        'payment_date',
        'reference',
        'payment_method',
        'notes',
        'recorded_by_admin_id',
    ];

    protected $casts = [
        'currency'         => FinancialCurrency::class,
        'amount'           => 'decimal:4',
        'allocated_amount' => 'decimal:4',
        'payment_date'     => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'recorded_by_admin_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocationProxy::modelClass(), 'payment_id');
    }
}
