<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Marketplace\Contracts\OrderFinancial as OrderFinancialContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Sales\Models\OrderProxy;

class OrderFinancial extends Model implements OrderFinancialContract
{
    protected $table = 'marketplace_order_financials';

    protected $guarded = ['*'];

    protected $casts = [
        'currency' => FinancialCurrency::class,
        'product_subtotal' => 'decimal:4',
        'product_discount_amount' => 'decimal:4',
        'discounted_product_subtotal' => 'decimal:4',
        'company_fee_rate' => 'decimal:4',
        'company_fee_amount' => 'decimal:4',
        'delivery_amount' => 'decimal:4',
        'delivery_discount_amount' => 'decimal:4',
        'product_tax_amount' => 'decimal:4',
        'delivery_tax_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'customer_financial_total' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $snapshot) {
            throw new FinancialRecordImmutableException('Order financial snapshots are immutable.');
        });

        static::deleting(function (self $snapshot) {
            throw new FinancialRecordImmutableException('Order financial snapshots cannot be deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderProxy::modelClass(), 'order_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItemFinancialProxy::modelClass(), 'order_financial_id');
    }
}