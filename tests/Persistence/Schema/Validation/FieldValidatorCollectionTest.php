<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

final class FieldValidatorCollectionTestRule implements FieldValidatorRule
{
    public function checkUsage(FieldDescriptor $field): void {
    }

    public function toStorage(): array {
        return [];
    }

    public static function fromStorage(array $stored): static {
        return new static();
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }
}

final class FieldValidatorCollectionTest extends TestCase
{
    public function testGetReturnsTheRulesRegisteredUnderThatName(): void {
        $rules = [new FieldValidatorCollectionTestRule()];
        $collection = FieldValidatorCollection::fromArray(['title' => $rules]);

        self::assertSame($rules, $collection->get(FieldName::of('title')));
    }

    public function testGetOnAFieldWithNoRulesReturnsAnEmptyArrayRatherThanThrowing(): void {
        $collection = FieldValidatorCollection::empty();

        self::assertSame([], $collection->get(FieldName::of('sku')));
    }

    public function testHasDistinguishesAValidatedFieldFromAnUnvalidatedOne(): void {
        $collection = FieldValidatorCollection::fromArray([
            'title' => [new FieldValidatorCollectionTestRule()],
        ]);

        self::assertTrue($collection->has(FieldName::of('title')));
        self::assertFalse($collection->has(FieldName::of('sku')));
    }

    public function testIterationYieldsFieldNameKeysAndTheirRules(): void {
        $rules = [new FieldValidatorCollectionTestRule()];
        $collection = FieldValidatorCollection::fromArray(['title' => $rules]);

        $seen = [];
        foreach ($collection as $name => $value) {
            $seen[(string) $name] = $value;
        }

        self::assertSame(['title' => $rules], $seen);
    }

    public function testCountReflectsTheNumberOfValidatedFields(): void {
        $collection = FieldValidatorCollection::fromArray([
            'a' => [new FieldValidatorCollectionTestRule()],
            'b' => [new FieldValidatorCollectionTestRule()],
        ]);

        self::assertCount(2, $collection);
    }
}
