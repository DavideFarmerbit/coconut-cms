<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use InvalidArgumentException;
use LogicException;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConversionRegistry;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\InvalidParameters;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;

/**
 * An editor save's operations as JSON, one object each, its kind named by
 * "op": `{"op": "renameField", "type": "shop:boat", "old": "length", "new":
 * "size"}`. A default is in stored form; a missing "default" key gives none,
 * `"default": null` pins null. A converter or initializer is its registered
 * identifier and its parameters: `{"kind": "coconut-cms:text-to-number",
 * "to": "int", "onFailure": "default"}`.
 */
final readonly class OperationForm
{
    public function __construct(
        private FieldForm $fields,
        private ConversionRegistry $conversions,
    ) {
    }

    /**
     * Every operation $data lists, at $path in the input.
     * @return list<SchemaOperation>
     * @throws InvalidInput Naming the path at fault.
     */
    public function readAll(mixed $data, string $path): array {
        if (!is_array($data) || !array_is_list($data)) {
            throw InvalidInput::at($path, 'A list of operations is needed here.');
        }

        $operations = [];
        foreach ($data as $index => $operation) {
            $operations[] = $this->read($operation, "$path.$index");
        }

        return $operations;
    }

    /** The operation $data describes, at $path. */
    public function read(mixed $data, string $path): SchemaOperation {
        $data = Json::object($data, $path);
        $op = Json::string($data, 'op', $path);
        $keys = match ($op) {
            'createType' => ['type', 'parent'],
            'renameType', 'renameField' => $op === 'renameType' ? ['old', 'new'] : ['type', 'old', 'new'],
            'deleteType' => ['type'],
            'addField' => ['type', 'field', 'default', 'initializer'],
            'removeField', 'makeRequired' => ['type', 'field'],
            'moveField' => ['type', 'field', 'to', 'default', 'initializer'],
            'retypeField' => ['type', 'field', 'converter', 'default'],
            'setDefault' => ['type', 'path', 'value'],
            'inheritDefault' => ['type', 'path'],
            default => throw InvalidInput::at("$path.op", sprintf('No operation "%s". Known: createType, renameType, deleteType, addField, removeField, renameField, moveField, makeRequired, retypeField, setDefault, inheritDefault.', $op)),
        };
        Json::onlyKeys($data, ['op', ...$keys], $path);
        $string = static fn (string $key): string => Json::string($data, $key, $path);

        try {
            return $this->build($op, $data, $path, $string);
        } catch (InvalidArgumentException $invalid) {
            throw InvalidInput::at($path, $invalid->getMessage());
        }
    }

    /**
     * The $op operation from $data: a malformed field name throws InvalidArgumentException.
     * @param array<string, mixed> $data
     * @param callable(string): string $string Reads a required string of $data.
     */
    private function build(string $op, array $data, string $path, callable $string): SchemaOperation {
        return match ($op) {
            'createType' => new CreateType($string('type'), Json::optionalString($data, 'parent', $path)),
            'renameType' => new RenameType($string('old'), $string('new')),
            'deleteType' => new DeleteType($string('type')),
            'addField' => new AddField(
                $string('type'),
                $this->fields->read($data['field'] ?? null, "$path.field"),
                self::defaultOf($data, 'default'),
                isset($data['initializer']) ? $this->initializer($data['initializer'], "$path.initializer") : null,
            ),
            'removeField' => new RemoveField($string('type'), $string('field')),
            'renameField' => new RenameField($string('type'), $string('old'), $string('new')),
            'moveField' => new MoveField(
                $string('type'),
                $string('field'),
                $string('to'),
                self::defaultOf($data, 'default'),
                isset($data['initializer']) ? $this->initializer($data['initializer'], "$path.initializer") : null,
            ),
            'makeRequired' => new MakeRequired($string('type'), $string('field')),
            'retypeField' => new RetypeField(
                $string('type'),
                $this->fields->read($data['field'] ?? null, "$path.field"),
                isset($data['converter']) ? $this->converter($data['converter'], "$path.converter") : null,
                self::defaultOf($data, 'default'),
            ),
            'setDefault' => new SetDefault($string('type'), $string('path'), self::defaultOf($data, 'value') ?? throw InvalidInput::at("$path.value", 'Missing.')),
            'inheritDefault' => new InheritDefault($string('type'), $string('path')),
            default => throw new LogicException(sprintf('"%s" was checked as known.', $op)),
        };
    }

    /**
     * The default $data gives at $key; null when the key is missing.
     * @param array<string, mixed> $data
     */
    private static function defaultOf(array $data, string $key): ?DefaultValue {
        return array_key_exists($key, $data) ? new DefaultValue($data[$key]) : null;
    }

    private function converter(mixed $data, string $path): FieldConverter {
        [$identifier, $parameters] = self::conversionOf($data, $path);
        try {
            return $this->conversions->converter($identifier, $parameters);
        } catch (InvalidParameters $refused) {
            throw InvalidInput::at(Json::at($path, $refused->parameter ?? 'kind'), $refused->getMessage());
        }
    }

    private function initializer(mixed $data, string $path): FieldInitializer {
        [$identifier, $parameters] = self::conversionOf($data, $path);
        try {
            return $this->conversions->initializer($identifier, $parameters);
        } catch (InvalidParameters $refused) {
            throw InvalidInput::at(Json::at($path, $refused->parameter ?? 'kind'), $refused->getMessage());
        }
    }

    /** @return array{string, array<string, mixed>} The identifier $data names in "kind", and its other keys. */
    private static function conversionOf(mixed $data, string $path): array {
        $data = Json::object($data, $path);
        $identifier = Json::string($data, 'kind', $path);
        unset($data['kind']);

        return [$identifier, $data];
    }

    /**
     * $operation as JSON, the way read() takes it.
     * @return array<string, mixed>
     */
    public function write(SchemaOperation $operation): array {
        return match (true) {
            $operation instanceof CreateType => ['op' => 'createType', 'type' => $operation->type, 'parent' => $operation->parent],
            $operation instanceof RenameType => ['op' => 'renameType', 'old' => $operation->old, 'new' => $operation->new],
            $operation instanceof DeleteType => ['op' => 'deleteType', 'type' => $operation->type],
            $operation instanceof AddField => ['op' => 'addField', 'type' => $operation->type, 'field' => $this->fields->write($operation->field)]
                + self::defaultForm('default', $operation->default)
                + ($operation->initializer === null ? [] : ['initializer' => $this->conversionForm($operation->initializer)]),
            $operation instanceof RemoveField => ['op' => 'removeField', 'type' => $operation->type, 'field' => (string) $operation->field],
            $operation instanceof RenameField => ['op' => 'renameField', 'type' => $operation->type, 'old' => (string) $operation->old, 'new' => (string) $operation->new],
            $operation instanceof MoveField => ['op' => 'moveField', 'type' => $operation->type, 'field' => (string) $operation->field, 'to' => $operation->to]
                + self::defaultForm('default', $operation->default)
                + ($operation->initializer === null ? [] : ['initializer' => $this->conversionForm($operation->initializer)]),
            $operation instanceof MakeRequired => ['op' => 'makeRequired', 'type' => $operation->type, 'field' => (string) $operation->field],
            $operation instanceof RetypeField => ['op' => 'retypeField', 'type' => $operation->type, 'field' => $this->fields->write($operation->field)]
                + ($operation->converter === null ? [] : ['converter' => $this->conversionForm($operation->converter)])
                + self::defaultForm('default', $operation->default),
            $operation instanceof SetDefault => ['op' => 'setDefault', 'type' => $operation->type, 'path' => $operation->path, 'value' => $operation->value->value],
            $operation instanceof InheritDefault => ['op' => 'inheritDefault', 'type' => $operation->type, 'path' => $operation->path],
            default => throw new LogicException(sprintf('%s has no JSON form.', $operation::class)),
        };
    }

    /** @return array<string, mixed> $default under $key; nothing for none. */
    private static function defaultForm(string $key, ?DefaultValue $default): array {
        return $default === null ? [] : [$key => $default->value];
    }

    /** @return array<string, mixed> */
    private function conversionForm(FieldConverter|FieldInitializer $conversion): array {
        [$identifier, $parameters] = $this->conversions->describe($conversion) ?? throw new LogicException(sprintf('%s is registered by no module: it has no JSON form.', $conversion::class));

        return ['kind' => $identifier, ...$parameters];
    }
}
