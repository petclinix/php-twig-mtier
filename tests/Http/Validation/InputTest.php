<?php

declare(strict_types=1);

namespace App\Tests\Http\Validation;

use App\Http\Validation\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testStringTrimsWhitespaceFromPostValue(): void
    {
        //arrange
        $_POST['name'] = '  Bilbo  ';

        //act + assert
        self::assertSame('Bilbo', Input::string('name'));
    }

    public function testStringReturnsDefaultWhenKeyIsMissing(): void
    {
        //act + assert
        self::assertSame('', Input::string('missing'));
        self::assertSame('fallback', Input::string('missing', 'fallback'));
    }

    public function testRawDoesNotTrimWhitespace(): void
    {
        //arrange
        $_POST['password'] = '  secret  ';

        //act + assert
        self::assertSame('  secret  ', Input::raw('password'));
    }

    public function testIntCastsPostValueToInteger(): void
    {
        //arrange
        $_POST['pet_id'] = '42';

        //act + assert
        self::assertSame(42, Input::int('pet_id'));
    }

    public function testIntReturnsDefaultWhenKeyIsMissing(): void
    {
        //act + assert
        self::assertSame(0, Input::int('missing'));
        self::assertSame(7, Input::int('missing', 7));
    }
}
