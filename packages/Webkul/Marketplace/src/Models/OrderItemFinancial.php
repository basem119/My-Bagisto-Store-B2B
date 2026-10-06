<?php

namespace Webkul\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Marketplace\Contracts\OrderItemFinancial as OrderItemFinancialContract;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Sales\Models\OrderItemProxy;

class OrderItemFinancial extends Model implements OrderItemFinancialContract
{
    protected $table = 'marketplace_order_item_financials';

    protected $guarded = ['*'];

    protected $casts = [
        'currency' => FinancialCurrency::class,
        'quantity' => 'decimal:4',
        'vendor_price' => 'decimal:4',
        'discounted_product_basis' => 'decimal:4',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'vendor_net_amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $snapshot) {
            throw new FinancialRecordImmutableException('Order item financial snapshots are immutable.');
        });

        static::deleting(function (self $snapshot) {
            throw new FinancialRecordImmutableException('Order item financial snapshots cannot be deleted.');
        });
    }

    public function orderFinancial(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialProxy::modelClass(), 'order_financial_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItemProxy::modelClass(), 'order_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProxy::modelClass(), 'vendor_id');
    }
}