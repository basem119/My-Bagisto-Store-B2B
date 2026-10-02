<?php

namespace Webkul\Marketplace\Enums;

/**
 * Role of a customer within a single vendor's membership (vendor-scoped, not global).
 */
enum VendorUserRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case STAFF = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Administrator',
            self::MANAGER => 'Manager',
            self::STAFF => 'Staff',
        };
    }

    /**
     * Roles permitted to manage vendor staff/membership.
     */
    public static function managerialRoles(): array
    {
        return [self::OWNER, self::ADMIN];
    }
}
