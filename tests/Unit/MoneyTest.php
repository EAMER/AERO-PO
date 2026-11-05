<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return ['zero' => ['0', 0], 'one cent' => ['0.01', 1], 'one decimal' => ['1.1', 110], 'large amount' => ['1234567890.12', 123456789012]];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_stored_as_exact_minor_units(string $amount, int $minor): void
    {
        $this->assertSame($minor, Money::minor($amount));
    }

    public static function invalidAmounts(): array
    {
        return ['negative' => ['-1'], 'sub-cent' => ['1.001'], 'exponent' => ['1e3'], 'too large' => ['12345678901'], 'separator' => ['1,200.00']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_unsupported_amounts_are_rejected(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::minor($amount);
    }

    public function test_amount_formatting_preserves_two_decimal_places(): void
    {
        $this->assertSame('1.10', Money::format(110));
    }
}
