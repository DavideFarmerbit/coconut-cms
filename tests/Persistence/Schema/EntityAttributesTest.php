<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Method\DefaultInstance;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

final class EntityAttributesMaxLength implements FieldValidatorRule
{
    public function __construct(private readonly int $max) {
    }

    public function checkUsage(FieldDescriptor $field): void {
        if ($field->scalarType() !== ScalarType::String && $field->scalarType() !== ScalarType::Text) {
            throw new InvalidArgumentException('MaxLength only applies to a String or Text scalar field.');
        }
    }

    public function validate(mixed $value): ValidationResult {
        return strlen((string) $value) > $this->max
            ? ValidationResult::invalid(sprintf('must be at most %d characters', $this->max))
            : ValidationResult::valid();
    }
}

final class EntityAttributesGreaterThan implements PrototypeValidatorRule
{
    public function __construct(
        private readonly string $greaterField,
        private readonly string $lesserField,
    ) {
    }

    public function checkUsage(FieldDescriptorCollection $fields): void {
        foreach ([$this->greaterField, $this->lesserField] as $name) {
            if (!$fields->has(FieldName::of($name))) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s".', $name));
            }
        }
    }

    public function validate(object $entity): ValidationResult {
        $reflection = new ReflectionClass($entity);
        $greater = $reflection->getProperty($this->greaterField)->getValue($entity);
        $lesser = $reflection->getProperty($this->lesserField)->getValue($entity);

        return $greater > $lesser
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('%s must be greater than %s', $this->greaterField, $this->lesserField));
    }
}

#[Entity(table: 'products', editorExtensible: true)]
#[Unique('sku')]
#[Unique('slug', 'locale')]
#[PrototypeValidator(new EntityAttributesGreaterThan('endDate', 'startDate'))]
final class EntityAttributesSubject
{
    #[FieldPermission(read: 'anyone', write: 'editor')]
    #[FieldValidator(new EntityAttributesMaxLength(10))]
    private string $wholesaleCost;

    private int $startDate = 1;

    private int $endDate = 2;

    #[DefaultInstance]
    private static function make(): self {
        return new self();
    }
}

final class EntityAttributesTest extends TestCase
{
    public function testEntityAttributeReflectsTableAndEditorExtensible(): void {
        $attribute = (new ReflectionClass(EntityAttributesSubject::class))
            ->getAttributes(Entity::class)[0]
            ->newInstance();

        self::assertSame('products', $attribute->table);
        self::assertTrue($attribute->editorExtensible);
    }

    public function testDefaultInstanceMarksTheFactoryMethod(): void {
        $method = (new ReflectionClass(EntityAttributesSubject::class))->getMethod('make');

        self::assertCount(1, $method->getAttributes(DefaultInstance::class));
    }

    public function testUniqueIsRepeatableAndStoresEachGroup(): void {
        $attributes = (new ReflectionClass(EntityAttributesSubject::class))->getAttributes(Unique::class);

        self::assertCount(2, $attributes);
        self::assertSame(['sku'], $attributes[0]->newInstance()->fields);
        self::assertSame(['slug', 'locale'], $attributes[1]->newInstance()->fields);
    }

    public function testUniqueWithNoFieldsThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        new Unique();
    }

    public function testFieldPermissionReflectsReadAndWriteRoles(): void {
        $attribute = (new ReflectionClass(EntityAttributesSubject::class))
            ->getProperty('wholesaleCost')
            ->getAttributes(FieldPermission::class)[0]
            ->newInstance();

        self::assertSame('anyone', $attribute->read);
        self::assertSame('editor', $attribute->write);
    }

    public function testFieldValidatorStoresConstructedRuleInstances(): void {
        $attribute = (new ReflectionClass(EntityAttributesSubject::class))
            ->getProperty('wholesaleCost')
            ->getAttributes(FieldValidator::class)[0]
            ->newInstance();

        self::assertCount(1, $attribute->rules);
        self::assertInstanceOf(EntityAttributesMaxLength::class, $attribute->rules[0]);
    }

    public function testFieldValidatorRuleAcceptsAMatchingField(): void {
        $rule = new EntityAttributesMaxLength(10);

        $rule->checkUsage(FieldDescriptor::scalar('wholesaleCost', ScalarType::String));

        self::assertTrue($rule->validate('short')->isValid());
    }

    public function testFieldValidatorRuleRejectsAMismatchedField(): void {
        $rule = new EntityAttributesMaxLength(10);

        $this->expectException(InvalidArgumentException::class);

        $rule->checkUsage(FieldDescriptor::scalar('quantity', ScalarType::Int));
    }

    public function testFieldValidatorRuleValidateFailsOverMax(): void {
        $rule = new EntityAttributesMaxLength(5);
        $result = $rule->validate('too long for this');

        self::assertFalse($result->isValid());
        self::assertSame('must be at most 5 characters', $result->error());
    }

    public function testPrototypeValidatorStoresConstructedRuleInstance(): void {
        $attribute = (new ReflectionClass(EntityAttributesSubject::class))
            ->getAttributes(PrototypeValidator::class)[0]
            ->newInstance();

        self::assertCount(1, $attribute->rules);
        self::assertInstanceOf(EntityAttributesGreaterThan::class, $attribute->rules[0]);
    }

    public function testPrototypeValidatorRuleAcceptsKnownFieldNames(): void {
        $rule = new EntityAttributesGreaterThan('endDate', 'startDate');

        $rule->checkUsage(FieldDescriptorCollection::fromArray([
            'startDate' => FieldDescriptor::scalar('startDate', ScalarType::Int),
            'endDate' => FieldDescriptor::scalar('endDate', ScalarType::Int),
        ]));

        self::assertTrue($rule->validate(new EntityAttributesSubject())->isValid());
    }

    public function testPrototypeValidatorRuleRejectsAnUnknownFieldName(): void {
        $rule = new EntityAttributesGreaterThan('endDate', 'doesNotExist');

        $this->expectException(InvalidArgumentException::class);

        $rule->checkUsage(FieldDescriptorCollection::fromArray([
            'startDate' => FieldDescriptor::scalar('startDate', ScalarType::Int),
            'endDate' => FieldDescriptor::scalar('endDate', ScalarType::Int),
        ]));
    }

    public function testPrototypeValidatorRuleValidateFailsWhenOrderIsWrong(): void {
        $rule = new EntityAttributesGreaterThan('startDate', 'endDate');
        $result = $rule->validate(new EntityAttributesSubject());

        self::assertFalse($result->isValid());
        self::assertSame('startDate must be greater than endDate', $result->error());
    }
}
