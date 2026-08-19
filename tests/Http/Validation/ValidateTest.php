<?php

declare(strict_types=1);

namespace App\Tests\Http\Validation;

use App\Http\Validation\Validate;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    public function testDateParsesAMatchingInput(): void
    {
        //act
        $date = Validate::date('2026-08-19', 'Y-m-d');

        //assert
        self::assertNotNull($date);
        self::assertSame('2026-08-19', $date->format('Y-m-d'));
    }

    public function testDateReturnsNullForMalformedInput(): void
    {
        //act + assert
        self::assertNull(Validate::date('not-a-date', 'Y-m-d'));
    }

    public function testDateReturnsNullForEmptyInput(): void
    {
        //act + assert
        self::assertNull(Validate::date('', 'Y-m-d'));
    }
}
