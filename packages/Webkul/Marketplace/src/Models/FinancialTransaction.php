<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\FinancialTransaction as FinancialTransactionContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Sales\Models\OrderProxy;
use Webkul\User\Models\AdminProxy;

class FinancialTransaction extends Model implements FinancialTransactionContract
{
    protected $table = 'marketplace_financial_transactions';

    protected $guarded = ['*'];

    protected $casts = [
        'currency' => FinancialCurrency::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (self $transaction) {
            throw new FinancialRecordImmutableException('Financial transactions are immutable. Post a reversing transaction instead.');
        });

        static::deleting(function (self $transaction) {
            throw new FinancialRecordImmutableException('Financial transactions cannot be deleted.');
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntryProxy::modelClass(), 'financial_transaction_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItemProxy::modelClass(), 'order_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProxy::modelClass(), 'vendor_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'created_by');
    }
}
