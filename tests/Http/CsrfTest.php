<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Csrf;
use App\Http\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        Session::start();
        unset($_SESSION['_csrf_token']);
    }

    public function testTokenGeneratesAndPersistsAcrossCalls(): void
    {
        //act
        $first = Csrf::token();
        $second = Csrf::token();

        //assert
        self::assertNotSame('', $first);
        self::assertSame($first, $second);
    }

    public function testVerifyReturnsTrueForAMatchingToken(): void
    {
        //arrange
        $token = Csrf::token();

        //act + assert
        self::assertTrue(Csrf::verify($token));
    }

    public function testVerifyReturnsFalseForAMismatchedToken(): void
    {
        //arrange
        Csrf::token();

        //act + assert
        self::assertFalse(Csrf::verify('not-the-token'));
    }

    public function testVerifyReturnsFalseForAnEmptySubmission(): void
    {
        //arrange
        Csrf::token();

        //act + assert
        self::assertFalse(Csrf::verify(''));
    }

    public function testVerifyReturnsFalseWhenNoTokenHasBeenIssued(): void
    {
        //act + assert
        self::assertFalse(Csrf::verify('anything'));
    }
}
