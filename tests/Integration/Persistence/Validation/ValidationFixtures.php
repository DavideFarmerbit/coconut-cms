<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Validation;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxItems;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxLength;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\OnItems;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Pattern;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Range;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation\ChecksAnyField;

/** A string that isn't empty. */
final readonly class ValRequired implements FieldValidatorRule
{
    use ChecksAnyField;

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return $value === '' || $value === null ? ValidationResult::invalid('is required') : ValidationResult::valid();
    }
}

/** A string of at most $max characters. */
final readonly class ValMaxLength implements FieldValidatorRule
{
    public function __construct(public int $max) {
    }

    public static function fits(): FieldSignature {
        return FieldSignature::text();
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return mb_strlen($value) <= $this->max ? ValidationResult::valid() : ValidationResult::invalid(sprintf('must be at most %d characters', $this->max));
    }
}

/** On an Embed: its "weight" between two bounds, read through its view, the error on the weight itself. */
final readonly class ValWeightBetween implements FieldValidatorRule
{
    use ChecksAnyField;

    public function __construct(public int $min, public int $max) {
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        $weight = $value->get('weight');

        return $weight >= $this->min && $weight <= $this->max
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('must be between %d and %d', $this->min, $this->max))->atField('weight');
    }
}

/** On an owned slot: only objects of $class, or a class extending it; an empty slot passes. */
final readonly class ValOnlyType implements FieldValidatorRule
{
    use ChecksAnyField;

    /** @param class-string $class */
    public function __construct(public string $class) {
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return $value === null || $value->isA(NativeIdentifiers::of($this->class)) ? ValidationResult::valid() : ValidationResult::invalid(sprintf('must be a %s', $this->class));
    }
}

/** An int above zero. */
final readonly class ValPositive implements FieldValidatorRule
{
    use ChecksAnyField;

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return $value > 0 ? ValidationResult::valid() : ValidationResult::invalid('must be above zero');
    }
}

/** Across fields: a sold vehicle has a price. */
final readonly class ValSoldHasPrice implements PrototypeValidatorRule
{
    public function checkUsage(FieldDescriptorCollection $fields): void {
    }

    public function validate(EntityView $entity): ValidationResult {
        return !$entity->get('sold') || $entity->get('price') > 0 ? ValidationResult::valid() : ValidationResult::invalid('a sold vehicle needs a price');
    }
}

#[Entity(identifier: 'val:chassis')]
final class ValChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;
}

#[Entity(identifier: 'val:engine')]
class ValEngine
{
    #[Scalar(ScalarType::Int), FieldValidator(new ValPositive())]
    public int $horsepower = 100;
}

#[Entity(identifier: 'val:turbo_engine')]
final class ValTurboEngine extends ValEngine
{
}

#[Entity(identifier: 'val:vehicle'), PrototypeValidator(new ValSoldHasPrice())]
final class ValVehicle
{
    #[Scalar(ScalarType::String), FieldValidator(new ValRequired(), new ValMaxLength(10))]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $price = 0;

    #[Scalar(ScalarType::Bool)]
    public bool $sold = false;

    #[Embed, FieldValidator(new ValWeightBetween(500, 2000))]
    public ValChassis $chassis;

    #[OwningReference, FieldValidator(new ValOnlyType(ValTurboEngine::class))]
    public ?ValEngine $engine = null;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: ValEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable), FieldValidator(new MaxItems(3), new OnItems(new ValMaxLength(5)))]
    public array $tags = [];

    public function __construct() {
        $this->chassis = new ValChassis();
    }
}

/** Owned by a product: its serial unique among one product's parts. */
#[Entity(identifier: 'val:part'), Unique('serial')]
final class ValPart
{
    #[Scalar(ScalarType::String)]
    public ?string $serial = null;
}

/** An Embed item: its text unique among one product's labels. */
#[Entity(identifier: 'val:label'), Unique('text')]
final class ValLabel
{
    #[Scalar(ScalarType::String)]
    public string $text = '';
}

#[Entity(identifier: 'val:product'), Unique('code'), Unique('slug')]
final class ValProduct
{
    #[Scalar(ScalarType::String)]
    public string $code = '';

    /** Optional but unique: nullable, defaulting to null. */
    #[Scalar(ScalarType::String)]
    public ?string $slug = null;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: ValPart::class, itemNullability: Nullability::Nullable)]
    public array $parts = [];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: ValLabel::class)]
    public array $labels = [];
}

/** Checked by the core's built-in rules. */
#[Entity(identifier: 'val:offer')]
final class ValOffer
{
    #[Scalar(ScalarType::String), FieldValidator(new Pattern('^[A-Z]{2}\d{2}$'))]
    public string $code = 'AB12';

    #[Scalar(ScalarType::Decimal), FieldValidator(new Range(min: 0, max: 99.99))]
    public string $price = '10.00';

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Float, itemNullability: Nullability::NotNullable), FieldValidator(new OnItems(new Range(max: 5)))]
    public array $ratings = [];

    #[Scalar(ScalarType::Text), FieldValidator(new MaxLength(8))]
    public ?string $note = null;
}

/** The validation tests' types. */
final class ValidationFixtures
{
    public const CLASSES = [ValChassis::class, ValEngine::class, ValTurboEngine::class, ValVehicle::class, ValPart::class, ValLabel::class, ValProduct::class, ValOffer::class];
}

final class ValidationModule implements Module
{
    public function name(): string {
        return 'val';
    }

    public function classes(): array {
        return ValidationFixtures::CLASSES;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function converters(): array {
        return [];
    }

    public function validators(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}
