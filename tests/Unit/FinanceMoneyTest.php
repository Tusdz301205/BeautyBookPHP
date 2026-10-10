<?php

namespace Tests\Unit;

use App\Services\Finance\Money;
use PHPUnit\Framework\TestCase;

class FinanceMoneyTest extends TestCase
{
    public function test_large_proportional_allocation_does_not_multiply_past_integer_capacity():void
    {
        $this->assertSame(33333333333333,Money::multiplyDivideRound(99999999999999,33333333333333,99999999999999));
        $this->assertSame(50000000000000,Money::multiplyDivideRound(99999999999999,5000,10000));
        $this->assertSame(1,Money::multiplyDivideRound(1,1,2));
        $this->assertSame(0,Money::multiplyDivideRound(1,1,3));
        $this->assertSame(3,Money::multiplyDivideRound(5,1,2));
        $this->assertSame(0,Money::multiplyDivideRound(0,99999999999999,3));
    }
    public function test_money_parsing_preserves_decimal_json_values():void
    {
        $this->assertSame(10,Money::cents(0.10));
        $this->assertSame(99999999999999,Money::cents('999999999999.99'));
        $this->assertSame('-0.01',Money::decimal(-1));
    }
}
