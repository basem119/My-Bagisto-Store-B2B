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

    /**
     * Centralized transition graph — the only place that decides which status
     * changes are valid. Nothing else in the codebase should hard-code this.
     *
     *   pending   -> active (approve), rejected (reject)
     *   active    -> suspended, inactive
     *   suspended -> active (reactivate)
     *   inactive  -> active (reactivate)
     *   rejected  -> (terminal; no further transitions in this phase)
     */
    public static function allowedTransitions(): array
    {
        return [
            self::PENDING->value => [self::ACTIVE, self::REJECTED],
            self::ACTIVE->value => [self::SUSPENDED, self::INACTIVE],
            self::SUSPENDED->value => [self::ACTIVE],
            self::INACTIVE->value => [self::ACTIVE],
            self::REJECTED->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::allowedTransitions()[$this->value] ?? [], true);
    }
}
