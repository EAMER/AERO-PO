<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function minor(string|int|float $amount): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', (string) $amount, $matches)) {
            throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function format(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
