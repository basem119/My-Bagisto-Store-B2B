<?php

namespace Webkul\Marketplace\Enums;

/**
 * Centralized vendor lifecycle status. Never compare against raw strings elsewhere.
 */
enum VendorStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case REJECTED = 'rejected';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::REJECTED => 'Rejected',
            self::INACTIVE => 'Inactive',
        };
    }
}
