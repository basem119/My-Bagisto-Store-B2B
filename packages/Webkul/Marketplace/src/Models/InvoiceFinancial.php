<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Marketplace\Contracts\InvoiceFinancial as InvoiceFinancialContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Sales\Models\InvoiceProxy;

class InvoiceFinancial extends Model implements InvoiceFinancialContract
{
    protected $table = 'marketplace_invoice_financials';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'currency' => FinancialCurrency::class,
        'company_fee_amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $allocation) {
            throw new FinancialRecordImmutableException('Invoice financial allocations are immutable.');
        });

        static::deleting(function (self $allocation) {
            throw new FinancialRecordImmutableException('Invoice financial allocations cannot be deleted.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(InvoiceProxy::modelClass(), 'invoice_id');
    }

    public function orderFinancial(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialProxy::modelClass(), 'order_financial_id');
    }
}