<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Field\Validator;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Field\Validator\MaxLengthValidator;

final class MaxLengthValidatorTest extends TestCase
{
    public function testAcceptsStringsAtOrUnderTheLimit(): void
    {
        $validator = new MaxLengthValidator(3);

        self::assertTrue($validator->validate('abc'));
        self::assertTrue($validator->validate('a'));
    }

    public function testRejectsStringsOverTheLimit(): void
    {
        $validator = new MaxLengthValidator(3);

        self::assertFalse($validator->validate('abcd'));
    }

    public function testNonStringValuesAlwaysPass(): void
    {
        $validator = new MaxLengthValidator(3);

        self::assertTrue($validator->validate(12345));
        self::assertTrue($validator->validate(null));
    }
}
