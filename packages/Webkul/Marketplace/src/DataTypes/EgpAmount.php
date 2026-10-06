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
        $amount = (string) $amount;

        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $amount, $matches)) {
            throw new FinancialPostingException('Amounts must be non-negative decimal strings with at most four fractional digits.');
        }

        $wholeDigits = ltrim($matches[1], '0') ?: '0';

        if (strlen($wholeDigits) > 14) {
            throw new FinancialPostingException('The amount exceeds the supported DECIMAL(18,4) range.');
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $minorUnits = ((int) $wholeDigits * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minorUnits++;
        }

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
}
