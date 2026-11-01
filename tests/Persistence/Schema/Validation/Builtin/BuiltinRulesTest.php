<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxLength;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Pattern;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Range;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldRuleUsage;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;

final class BuiltinRulesTest extends TestCase
{
    public function testEachIsStoredUnderTheCoresNamespace(): void {
        self::assertSame('coconut-cms:max-length', (string) NativeIdentifiers::storedAs(MaxLength::class));
        self::assertSame('coconut-cms:range', (string) NativeIdentifiers::storedAs(Range::class));
        self::assertSame('coconut-cms:pattern', (string) NativeIdentifiers::storedAs(Pattern::class));
    }

    public function testMaxLengthCountsCharactersNotBytes(): void {
        $rule = new MaxLength(3);

        self::assertTrue($rule->validate('àèì')->isValid());
        self::assertTrue($rule->validate(null)->isValid());
        self::assertSame('must be at most 3 characters', $rule->validate('abcd')->error());
    }

    public function testRangeChecksEitherBoundOrBoth(): void {
        self::assertTrue((new Range(min: 1, max: 10))->validate(10)->isValid());
        self::assertSame('must be between 1 and 10', (new Range(min: 1, max: 10))->validate(0)->error());
        self::assertSame('must be at least 1.5', (new Range(min: 1.5))->validate(1.4)->error());
        self::assertSame('must be at most 3', (new Range(max: 3))->validate(4)->error());
        self::assertTrue((new Range(max: 3))->validate(null)->isValid());
    }

    public function testRangeComparesADecimalExactly(): void {
        $rule = new Range(min: -0.1, max: 99.99);

        self::assertTrue($rule->validate('99.990000')->isValid());
        self::assertFalse($rule->validate('99.990001')->isValid());
        self::assertTrue($rule->validate('-0.100000')->isValid());
        self::assertFalse($rule->validate('-0.100001')->isValid());
        self::assertTrue($rule->validate('0.000000')->isValid());
        self::assertFalse($rule->validate('123456789012.000000')->isValid());
        self::assertTrue((new Range(min: 0))->validate('0.000000')->isValid());
        self::assertFalse((new Range(min: 0))->validate('-5.000000')->isValid());
    }

    public function testARangeNeedsABoundInOrder(): void {
        $this->assertRefused(static fn () => new Range(), 'needs a min, a max, or both');
        $this->assertRefused(static fn () => new Range(min: 5, max: 1), 'is above its max');
    }

    public function testPatternMatchesUnicodeTextAndItsOwnDelimiter(): void {
        $code = new Pattern('^[A-Z]{2}\d{4}$');
        self::assertTrue($code->validate('AB1234')->isValid());
        self::assertSame('must match ^[A-Z]{2}\d{4}$', $code->validate('ab1234')->error());

        self::assertTrue((new Pattern('^\p{L}+$'))->validate('città')->isValid());
        self::assertTrue((new Pattern('a~b'))->validate('xa~by')->isValid());
        self::assertTrue($code->validate(null)->isValid());
    }

    public function testAPatternThatDoesntCompileIsRefused(): void {
        $this->assertRefused(static fn () => new Pattern('(unclosed'), 'is no valid regular expression');
    }

    public function testEachFitsOnlyWhatItChecksItemsIncluded(): void {
        $text = FieldDescriptor::scalar('name', ScalarType::String, Nullability::NotNullable);
        $number = FieldDescriptor::scalar('price', ScalarType::Decimal, Nullability::NotNullable);
        $tags = FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::Text, Nullability::NotNullable));
        $date = FieldDescriptor::scalar('built', ScalarType::Date, Nullability::NotNullable);

        foreach ([new MaxLength(5), new Pattern('x')] as $rule) {
            FieldRuleUsage::check($rule, $text);
            FieldRuleUsage::check($rule, $tags);
            $this->assertUsageRefused($rule, $number, 'checks a Scalar of string or text, and field "price" holds none');
        }

        FieldRuleUsage::check(new Range(min: 0), $number);
        $this->assertUsageRefused(new Range(min: 0), $text, 'checks a Scalar of int, float or decimal, and field "name" holds none');
        $this->assertUsageRefused(new Range(min: 0), $date, 'checks a Scalar of int, float or decimal');
    }

    public function testEachRoundTripsThroughStorage(): void {
        foreach ([new MaxLength(5), new Range(min: 1.5), new Range(max: 3), new Pattern('^a~$')] as $rule) {
            self::assertEquals($rule, $rule::fromStorage(json_decode(json_encode($rule->toStorage()), true)));
        }
    }

    private function assertUsageRefused(FieldValidatorRule $rule, FieldDescriptor $field, string $message): void {
        $this->assertRefused(static fn () => FieldRuleUsage::check($rule, $field), $message);
    }

    private function assertRefused(callable $attempt, string $message): void {
        try {
            $attempt();
            self::fail('Expected a refusal.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
