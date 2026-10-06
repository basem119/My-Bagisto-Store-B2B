<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\FinancialEntry as FinancialEntryContract;
use Webkul\Marketplace\Enums\FinancialAccountCode;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Sales\Models\OrderProxy;

class FinancialEntry extends Model implements FinancialEntryContract
{
    protected $table = 'marketplace_financial_entries';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $casts = [
        'account_code'  => FinancialAccountCode::class,
        'currency'      => FinancialCurrency::class,
        'debit_amount'  => 'decimal:4',
        'credit_amount' => 'decimal:4',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            throw new FinancialRecordImmutableException('Financial entries are immutable. Post a reversing transaction instead.');
        });

        static::deleting(function (self $entry) {
            throw new FinancialRecordImmutableException('Financial entries cannot be deleted.');
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransactionProxy::modelClass(), 'financial_transaction_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProxy::modelClass(), 'vendor_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItemProxy::modelClass(), 'order_item_id');
    }
}
