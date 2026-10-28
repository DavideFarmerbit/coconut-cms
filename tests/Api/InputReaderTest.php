<?php

namespace XyloIsCoding\CoconutCms\Tests\Api;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Api\Input\InputReader;
use XyloIsCoding\CoconutCms\Api\Input\InputShape;
use XyloIsCoding\CoconutCms\Tests\Api\Nested\FixtureTag;

/** InputReader and InputShape: typed inputs from plain data, errors by path. */
final class InputReaderTest extends TestCase
{
    protected function setUp(): void {
        // The fixture file holds several classes: load it by its own.
        class_exists(ApiFixtures::class);
    }

    /** The errors reading $data gives, by path. @return array<string, string> */
    private static function errorsOf(mixed $data, bool $fromStrings = false): array {
        try {
            (new InputReader())->read(FixtureInput::class, $data, $fromStrings);
        } catch (InvalidInput $invalid) {
            return $invalid->errors;
        }

        self::fail('The input was read.');
    }

    public function testEveryKindIsReadFromJson(): void {
        $input = (new InputReader())->read(FixtureInput::class, [
            'name' => 'cars',
            'limit' => 5,
            'ratio' => 2,
            'loud' => true,
            'color' => 'blue',
            'size' => 2,
            'filter' => ['path' => 'price'],
            'filters' => [['path' => 'name', 'tags' => [['label' => 'a'], ['label' => 'b']]]],
            'values' => [1, 'two', null, ['three']],
            'counts' => [3, 4],
        ]);

        self::assertSame('cars', $input->name);
        self::assertSame(5, $input->limit);
        self::assertSame(2.0, $input->ratio);
        self::assertTrue($input->loud);
        self::assertSame(FixtureColor::Blue, $input->color);
        self::assertSame(FixtureSize::Large, $input->size);
        self::assertEquals(new FixtureFilter('price'), $input->filter);
        self::assertEquals([new FixtureFilter('name', [new FixtureTag('a'), new FixtureTag('b')])], $input->filters);
        self::assertSame([1, 'two', null, ['three']], $input->values);
        self::assertSame([3, 4], $input->counts);
    }

    public function testAMissingOptionalKeyTakesItsDefault(): void {
        $input = (new InputReader())->read(FixtureInput::class, ['name' => 'cars']);

        self::assertSame(20, $input->limit);
        self::assertNull($input->color);
        self::assertSame(FixtureSize::Small, $input->size);
        self::assertSame([], $input->filters);
    }

    public function testEveryWrongValueIsNamedByItsPath(): void {
        self::assertSame([
            'name' => 'Missing.',
            'limit' => 'Must be an integer.',
            'color' => 'Must be one of "red", "blue".',
            'size' => 'Must be one of 1, 2.',
            'filters.0.tags.1.label' => 'Must be a string.',
            'filters.1' => 'Must be an object.',
            'counts' => 'Must be a list.',
            'colour' => 'Unknown: there is no such input.',
        ], self::errorsOf([
            'limit' => '5',
            'color' => 'green',
            'size' => 3,
            'filters' => [['path' => 'a', 'tags' => [['label' => 'x'], ['label' => 7]]], 'b'],
            'counts' => ['a' => 1],
            'colour' => 'red',
        ]));
    }

    public function testNullIsRefusedWhereTheTypeIsntNullable(): void {
        self::assertSame(['name' => 'Can\'t be null.', 'counts.0' => 'Can\'t be null.'], self::errorsOf(['name' => null, 'counts' => [null], 'color' => null]));
    }

    public function testTheInputMustBeAnObject(): void {
        self::assertSame(['' => 'Must be an object.'], self::errorsOf(['a', 'b']));
        self::assertSame(['' => 'Must be an object.'], self::errorsOf('cars'));
    }

    public function testCommandLineStringsAreReadFromTheirText(): void {
        $input = (new InputReader())->read(FixtureInput::class, [
            'name' => '42',
            'limit' => '-5',
            'ratio' => '0.5',
            'loud' => 'true',
            'size' => '2',
            'values' => ['200', 'diesel', '"200"', 'null'],
            'counts' => ['3'],
        ], fromStrings: true);

        self::assertSame('42', $input->name);
        self::assertSame(-5, $input->limit);
        self::assertSame(0.5, $input->ratio);
        self::assertTrue($input->loud);
        self::assertSame(FixtureSize::Large, $input->size);
        self::assertSame([200, 'diesel', '200', null], $input->values);
        self::assertSame([3], $input->counts);
    }

    public function testCommandLineTextThatIsntTheTypeIsRefused(): void {
        self::assertSame(['limit' => 'Must be an integer.', 'loud' => 'Must be true or false.'], self::errorsOf(['name' => 'a', 'limit' => '5.5', 'loud' => 'yes'], fromStrings: true));
    }

    /** @return array<string, array{class-string, string}> */
    public static function invalidShapes(): array {
        return [
            'a union' => [FixtureUnionInput::class, 'one named type, not a union'],
            'an array without its items' => [FixtureBareArrayInput::class, 'needs a @param naming its items'],
            'a class that isn\'t an input' => [FixtureObjectInput::class, 'not DateTimeImmutable'],
            'a nested invalid input' => [FixtureNestingInvalidInput::class, 'FixtureUnionInput::__construct($either)'],
            'not an input' => [FixtureResult::class, 'doesn\'t implement'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('invalidShapes')]
    public function testAnInputNoTransportCanBuildFailsWhenItsShapeIsRead(string $class, string $message): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        InputShape::of($class);
    }

    public function testAnInvalidNestedInputFailsEveryTime(): void {
        foreach ([1, 2] as $attempt) {
            try {
                InputShape::of(FixtureNestingInvalidInput::class);
                self::fail(sprintf('Attempt %d was read.', $attempt));
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
