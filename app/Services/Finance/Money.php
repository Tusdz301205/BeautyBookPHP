<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function cents(string|int|float $value): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $value, $parts)) throw ValidationException::withMessages(['amount' => 'Số tiền phải có tối đa hai chữ số thập phân.']);
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
    public static function decimal(int $cents): string
    {
        if ($cents < 0) return '-'.self::decimal(abs($cents));
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function multiplyDivideRound(int $amount, int $multiplier, int $divisor): int
    {
        if ($amount < 0 || $multiplier < 0 || $divisor < 1) throw new \InvalidArgumentException('Tỷ lệ tiền không hợp lệ.');
        $quotient = 0; $remainder = 0;
        // Long multiplication with a quotient/remainder pair avoids amount * multiplier overflow.
        foreach (str_split(decbin($multiplier)) as $bit) {
            if ($quotient > intdiv(PHP_INT_MAX, 2) || $remainder > intdiv(PHP_INT_MAX - ($bit === '1' ? $amount : 0), 2)) throw new \OverflowException('Số tiền vượt giới hạn.');
            $quotient *= 2;
            $remainder = $remainder * 2 + ($bit === '1' ? $amount : 0);
            $addition = intdiv($remainder, $divisor);
            if ($quotient > PHP_INT_MAX - $addition) throw new \OverflowException('Số tiền vượt giới hạn.');
            $quotient += $addition;
            $remainder %= $divisor;
        }
        $roundUp = $remainder >= intdiv($divisor, 2) + ($divisor % 2);
        if ($roundUp && $quotient === PHP_INT_MAX) throw new \OverflowException('Số tiền vượt giới hạn.');
        return $quotient + ($roundUp ? 1 : 0);
    }
}
