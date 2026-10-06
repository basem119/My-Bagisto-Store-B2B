<?php

namespace Webkul\Marketplace\Enums;

enum PaymentPlanStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
