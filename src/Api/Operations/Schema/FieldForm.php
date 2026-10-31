<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use InvalidArgumentException;
use LogicException;
use ReflectionEnum;
use RuntimeException;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;

/**
 * A field descriptor as JSON, the way types.get describes what a field
 * holds, with its name: `{"name": "length", "kind": "scalar", "scalarType":
 * "int", "nullable": false}`. An enum Scalar names its enum by identifier,
 * `"enum": "shop:color"`; a value object, its target, by identifier too. A
 * Collection's `item` is described the same way, without a name.
 */
final readonly class FieldForm
{
    public function __construct(
        private EnumRegistry $enums,
        private ValueObjectRegistry $valueObjects,
    ) {
    }

    /**
     * The descriptor $data describes, at $path in the input. $name is an
     * item's: its Collection's name.
     * @throws InvalidInput Naming the path at fault.
     */
    public function read(mixed $data, string $path, ?string $name = null): FieldDescriptor {
        $data = Json::object($data, $path);
        $allowed = $name === null ? ['name', 'kind', 'nullable', 'scalarType', 'enum', 'target', 'item'] : ['kind', 'nullable', 'scalarType', 'enum', 'target', 'item'];
        Json::onlyKeys($data, $allowed, $path);
        $name ??= Json::string($data, 'name', $path);
        $kind = FieldKind::tryFrom(Json::string($data, 'kind', $path)) ?? throw InvalidInput::at("$path.kind", sprintf('One of: %s.', implode(', ', array_map(static fn (FieldKind $kind): string => $kind->value, FieldKind::cases()))));
        $nullability = Json::bool($data, 'nullable', $path, false) ? Nullability::Nullable : Nullability::NotNullable;

        try {
            return match ($kind) {
                FieldKind::Scalar => $this->scalar($data, $path, $name, $nullability),
                FieldKind::ValueObject => $this->valueObject(Json::string($data, 'target', $path), "$path.target", $name, $nullability),
                FieldKind::Embed => FieldDescriptor::embed($name, Json::string($data, 'target', $path)),
                FieldKind::Reference => FieldDescriptor::reference($name, Json::string($data, 'target', $path)),
                FieldKind::OwningReference => FieldDescriptor::owningReference($name, Json::string($data, 'target', $path), $nullability),
                FieldKind::Collection => FieldDescriptor::collection($name, $this->read($data['item'] ?? throw InvalidInput::at("$path.item", 'Missing: what the Collection holds.'), "$path.item", $name)),
            };
        } catch (InvalidArgumentException|LogicException $refused) {
            throw InvalidInput::at($path, $refused->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function scalar(array $data, string $path, string $name, Nullability $nullability): FieldDescriptor {
        $type = ScalarType::tryFrom(Json::string($data, 'scalarType', $path)) ?? throw InvalidInput::at("$path.scalarType", sprintf('One of: %s.', implode(', ', array_map(static fn (ScalarType $type): string => $type->value, ScalarType::cases()))));
        if ($type !== ScalarType::Enum) {
            return FieldDescriptor::scalar($name, $type, $nullability);
        }

        $enum = $this->resolve(Json::string($data, 'enum', $path), "$path.enum", $this->enums->resolve(...));
        $backing = (string) (new ReflectionEnum($enum))->getBackingType() === 'int' ? ScalarType::Int : ScalarType::String;

        return FieldDescriptor::scalar($name, $type, $nullability, $enum, $backing);
    }

    private function valueObject(string $identifier, string $path, string $name, Nullability $nullability): FieldDescriptor {
        $class = $this->resolve($identifier, $path, $this->valueObjects->resolve(...));

        return FieldDescriptor::valueObject($name, $class, $class::indexable(), $nullability);
    }

    /**
     * The class registered as $identifier, by $resolve.
     * @param callable(Identifier): class-string $resolve
     * @return class-string
     */
    private function resolve(string $identifier, string $path, callable $resolve): string {
        try {
            return $resolve(Identifier::fromString($identifier));
        } catch (InvalidArgumentException|RuntimeException) {
            throw InvalidInput::at($path, sprintf('Nothing is registered as "%s".', $identifier));
        }
    }

    /**
     * $field as JSON, the way read() takes it; an item without its name.
     * @return array<string, mixed>
     */
    public function write(FieldDescriptor $field, bool $named = true): array {
        $form = $named ? ['name' => $field->name()] : [];
        $form['kind'] = $field->kind()->value;
        $form['nullable'] = $field->allowsNull();

        return $form + match ($field->kind()) {
            FieldKind::Scalar => $field->enumClass() === null
                ? ['scalarType' => $field->scalarType()->value]
                : ['scalarType' => $field->scalarType()->value, 'enum' => (string) $this->enums->identifierOf($field->enumClass())],
            FieldKind::ValueObject => ['target' => (string) $this->valueObjects->identifierOf($field->target())],
            FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference => ['target' => $field->target()],
            FieldKind::Collection => ['item' => $this->write($field->itemDescriptor(), false)],
        };
    }
}
