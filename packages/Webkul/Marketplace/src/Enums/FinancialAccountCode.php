<?php

namespace Webkul\Marketplace\Enums;

enum FinancialAccountCode: string
{
    case COMPANY_RECEIVABLE = 'company_receivable';
    case VENDOR_PAYABLE = 'vendor_payable';
    case PLATFORM_REVENUE = 'platform_revenue';
    case DELIVERY_REVENUE = 'delivery_revenue';
    case PLATFORM_CASH = 'platform_cash';

    public static function values(): array
    {
        return array_map(fn (self $account) => $account->value, self::cases());
    }
}
