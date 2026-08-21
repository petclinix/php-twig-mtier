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
        $_GET = [];
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

    public function testQueryTrimsWhitespaceFromQueryValue(): void
    {
        //arrange
        $_GET['name'] = '  Bilbo  ';

        //act + assert
        self::assertSame('Bilbo', Input::query('name'));
    }

    public function testQueryReturnsDefaultWhenKeyIsMissing(): void
    {
        //act + assert
        self::assertSame('', Input::query('missing'));
        self::assertSame('fallback', Input::query('missing', 'fallback'));
    }

    public function testRawQueryDoesNotTrimWhitespace(): void
    {
        //arrange
        $_GET['token'] = '  secret  ';

        //act + assert
        self::assertSame('  secret  ', Input::rawQuery('token'));
    }

    public function testQueryIntCastsQueryValueToInteger(): void
    {
        //arrange
        $_GET['vet_id'] = '42';

        //act + assert
        self::assertSame(42, Input::queryInt('vet_id'));
    }

    public function testQueryIntReturnsDefaultWhenKeyIsMissing(): void
    {
        //act + assert
        self::assertSame(0, Input::queryInt('missing'));
        self::assertSame(7, Input::queryInt('missing', 7));
    }
}
