<?php

namespace Webkul\Marketplace\DataTypes;

use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;

final class FinancialRate
{
    private const DENOMINATOR = 100_000_000;

    public static function normalize(int|string|null $rate): string
    {
        $value = (string) ($rate ?? '0');

        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', $value, $matches)) {
            throw new MarketplaceFinancializationException('Financial rates must be decimal percentages between 0 and 100.');
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 4, '0');
        $fractionValue = (int) $fraction;

        if ($whole > 100 || ($whole === 100 && $fractionValue > 0)) {
            throw new MarketplaceFinancializationException('Financial rates must be between 0 and 100 percent.');
        }

        return sprintf('%d.%04d', $whole, $fractionValue);
    }

    public static function percentOfMinorUnits(int $amount, int|string|null $rate): int
    {
        if ($amount < 0 || $amount > intdiv(PHP_INT_MAX, 100)) {
            throw new MarketplaceFinancializationException('A financial rate cannot be applied to an amount outside the supported range.');
        }

        return self::percentOfPrecisionUnits($amount * 100, $rate);
    }

    public static function percentOfPrecisionUnits(int $amount, int|string|null $rate): int
    {
        if ($amount < 0) {
            throw new MarketplaceFinancializationException('A financial rate cannot be applied to a negative amount.');
        }

        $normalizedRate = self::normalize($rate);
        [$whole, $fraction] = array_pad(explode('.', $normalizedRate, 2), 2, '0000');
        $rateUnits = ((int) $whole * 10_000) + (int) $fraction;
        $wholeResult = intdiv($amount, self::DENOMINATOR) * $rateUnits;
        $remainder = $amount % self::DENOMINATOR;
        $fractionalResult = intdiv(($remainder * $rateUnits) + intdiv(self::DENOMINATOR, 2), self::DENOMINATOR);

        if ($wholeResult > PHP_INT_MAX - $fractionalResult) {
            throw new MarketplaceFinancializationException('The calculated amount exceeds the supported range.');
        }

        return $wholeResult + $fractionalResult;
    }
}