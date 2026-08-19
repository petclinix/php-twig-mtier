<?php

declare(strict_types=1);

namespace App\Tests\Http\Validation;

use App\Http\Validation\ErrorBag;
use PHPUnit\Framework\TestCase;

final class ErrorBagTest extends TestCase
{
    public function testFreshErrorBagIsEmpty(): void
    {
        //arrange
        $errors = new ErrorBag();

        //act + assert
        self::assertTrue($errors->isEmpty());
        self::assertSame([], $errors->all());
    }

    public function testAddAppendsAMessageUnconditionally(): void
    {
        //arrange
        $errors = new ErrorBag();

        //act
        $errors->add('Something went wrong.');

        //assert
        self::assertFalse($errors->isEmpty());
        self::assertSame(['Something went wrong.'], $errors->all());
    }

    public function testAddIfOnlyAppendsWhenConditionIsTrue(): void
    {
        //arrange
        $errors = new ErrorBag();

        //act
        $errors->addIf(false, 'Should not appear.');
        $errors->addIf(true, 'Should appear.');

        //assert
        self::assertSame(['Should appear.'], $errors->all());
    }

    public function testMessagesAreKeptInAdditionOrder(): void
    {
        //arrange
        $errors = new ErrorBag();

        //act
        $errors->add('First.');
        $errors->addIf(true, 'Second.');
        $errors->add('Third.');

        //assert
        self::assertSame(['First.', 'Second.', 'Third.'], $errors->all());
    }
}
