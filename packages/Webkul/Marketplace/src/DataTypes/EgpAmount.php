<?php

namespace Webkul\Marketplace\DataTypes;

use Webkul\Marketplace\Exceptions\FinancialPostingException;

/**
 * EGP amounts are rounded half-up to the currency's two minor-unit digits.
 * Integers and decimal strings are accepted; binary floating-point is not.
 */
final class EgpAmount
{
    private const MAX_MINOR_UNITS = 9999999999999999;

    public static function toMinorUnits(int|string $amount): int
    {
        return self::precisionUnitsToMinorUnits(self::toPrecisionUnits($amount));
    }

    public static function toPrecisionUnits(int|string $amount): int
    {
        $amount = (string) $amount;

        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $amount, $matches)) {
            throw new FinancialPostingException('Amounts must be non-negative decimal strings with at most four fractional digits.');
        }

        $wholeDigits = ltrim($matches[1], '0') ?: '0';

        if (strlen($wholeDigits) > 14) {
            throw new FinancialPostingException('The amount exceeds the supported DECIMAL(18,4) range.');
        }

        return ((int) $wholeDigits * 10_000) + (int) str_pad($matches[2] ?? '', 4, '0');
    }

    public static function precisionUnitsToMinorUnits(int $precisionUnits): int
    {
        if ($precisionUnits < 0) {
            throw new FinancialPostingException('Amounts cannot be negative.');
        }

        $minorUnits = intdiv($precisionUnits, 100) + ((($precisionUnits % 100) >= 50) ? 1 : 0);

        if ($minorUnits > self::MAX_MINOR_UNITS) {
            throw new FinancialPostingException('The rounded amount exceeds the supported DECIMAL(18,4) range.');
        }

        return $minorUnits;
    }

    public static function toDatabaseDecimal(int $minorUnits): string
    {
        if ($minorUnits < 0 || $minorUnits > self::MAX_MINOR_UNITS) {
            throw new FinancialPostingException('The amount is outside the supported range.');
        }

        return sprintf('%d.%02d00', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    public static function toDatabasePrecisionDecimal(int $precisionUnits): string
    {
        if ($precisionUnits < 0 || $precisionUnits > 999999999999999999) {
            throw new FinancialPostingException('The amount is outside the supported DECIMAL(18,4) range.');
        }

        return sprintf('%d.%04d', intdiv($precisionUnits, 10_000), $precisionUnits % 10_000);
    }
}
