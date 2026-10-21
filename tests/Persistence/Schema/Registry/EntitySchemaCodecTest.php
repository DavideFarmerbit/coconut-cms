<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Registry;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

enum CodecStatus: string
{
    case Draft = 'draft';
}

final class CodecMoney implements StorableValue
{
    public function __construct(
        private readonly string $amount = '0 EUR',
    ) {
    }

    public function toStorage(): string {
        return $this->amount;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

final class CodecMaxLength implements FieldValidatorRule
{
    public function __construct(
        private readonly int $max,
    ) {
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function toStorage(): array {
        return ['max' => $this->max];
    }

    public static function fromStorage(array $stored): static {
        return new static($stored['max']);
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }
}

final class CodecNotEqual implements PrototypeValidatorRule
{
    public function __construct(
        private readonly string $left,
        private readonly string $right,
    ) {
    }

    public function checkUsage(FieldDescriptorCollection $fields): void {
    }

    public function toStorage(): array {
        return ['left' => $this->left, 'right' => $this->right];
    }

    public static function fromStorage(array $stored): static {
        return new static($stored['left'], $stored['right']);
    }

    public function validate(object $entity): ValidationResult {
        return ValidationResult::valid();
    }
}

#[Entity]
final class CodecPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 10;
}

#[Entity]
#[Unique('sku', 'region')]
#[PrototypeValidator(new CodecNotEqual('sku', 'region'))]
final class CodecProduct
{
    #[Scalar(ScalarType::String)]
    #[FieldPermission(read: 'viewer', write: 'editor')]
    #[FieldValidator(new CodecMaxLength(20), new CodecMaxLength(30))]
    private string $sku = '';

    #[Scalar(ScalarType::String)]
    private string $region = '';

    #[Scalar(ScalarType::Enum)]
    private CodecStatus $status = CodecStatus::Draft;

    #[ValueObject]
    private ?CodecMoney $price = null;

    #[Embed]
    private CodecPiston $piston;

    #[Reference]
    private ?CodecPiston $spare = null;

    #[OwningReference]
    private ?CodecPiston $owned = null;

    #[Collection(itemKind: FieldKind::Embed, itemTarget: CodecPiston::class)]
    private array $pistons = [];

    public function __construct() {
        $this->piston = new CodecPiston();
    }
}

final class EntitySchemaCodecTest extends TestCase
{
    private static function schemaOf(string $class): EntitySchema {
        $blueprint = NativeEntityReader::read($class);

        return new EntitySchema(
            identifier: $blueprint->identifier,
            parent: $blueprint->parent,
            table: $blueprint->table,
            editorExtensible: $blueprint->editorExtensible,
            fields: $blueprint->fields,
            fieldPermissions: $blueprint->fieldPermissions,
            fieldValidators: $blueprint->fieldValidators,
            uniqueGroups: $blueprint->uniqueGroups,
            prototypeValidators: $blueprint->prototypeValidators,
        );
    }

    private static function codec(): EntitySchemaCodec {
        $fieldValidators = new FieldValidatorRegistry();
        $fieldValidators->register(Identifier::of('test', 'max-length'), CodecMaxLength::class);
        $prototypeValidators = new PrototypeValidatorRegistry();
        $prototypeValidators->register(Identifier::of('test', 'not-equal'), CodecNotEqual::class);
        $valueObjects = new ValueObjectRegistry();
        $valueObjects->register(Identifier::of('test', 'money'), CodecMoney::class);

        return new EntitySchemaCodec($fieldValidators, $prototypeValidators, $valueObjects);
    }

    public function testASchemaRoundTripsThroughJson(): void {
        $schema = self::schemaOf(CodecProduct::class);
        $codec = self::codec();

        $decoded = $codec->decode(json_decode(json_encode($codec->encode($schema)), true));

        self::assertEquals($schema, $decoded);
    }

    public function testARuleIsStoredByItsIdentifierNeverItsClassName(): void {
        $encoded = self::codec()->encode(self::schemaOf(CodecProduct::class));

        self::assertSame(
            [['rule' => 'test:max-length', 'config' => ['max' => 20]], ['rule' => 'test:max-length', 'config' => ['max' => 30]]],
            $encoded['fieldValidators']['sku'],
        );
        self::assertSame([['rule' => 'test:not-equal', 'config' => ['left' => 'sku', 'right' => 'region']]], $encoded['prototypeValidators']);
        self::assertStringNotContainsString(CodecMaxLength::class, json_encode($encoded));
    }

    public function testAValueObjectIsStoredByItsIdentifierNeverItsClassName(): void {
        $encoded = self::codec()->encode(self::schemaOf(CodecProduct::class));

        self::assertSame('test:money', $encoded['fields']['price']['target']);
        self::assertStringNotContainsString(CodecMoney::class, json_encode($encoded));
    }

    public function testEncodingARuleNeverRegisteredThrows(): void {
        $valueObjects = new ValueObjectRegistry();
        $valueObjects->register(Identifier::of('test', 'money'), CodecMoney::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CodecMaxLength::class);

        (new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), $valueObjects))->encode(self::schemaOf(CodecProduct::class));
    }

    public function testEncodingAValueObjectNeverRegisteredThrows(): void {
        $fieldValidators = new FieldValidatorRegistry();
        $fieldValidators->register(Identifier::of('test', 'max-length'), CodecMaxLength::class);
        $prototypeValidators = new PrototypeValidatorRegistry();
        $prototypeValidators->register(Identifier::of('test', 'not-equal'), CodecNotEqual::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CodecMoney::class);

        (new EntitySchemaCodec($fieldValidators, $prototypeValidators, new ValueObjectRegistry()))->encode(self::schemaOf(CodecProduct::class));
    }
}
