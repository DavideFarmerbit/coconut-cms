<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use InvalidArgumentException;
use LogicException;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConversionRegistry;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Parameters\InvalidParameters;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CopyValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeOptional;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameFields;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameTypes;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\ReplaceType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetFieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetFieldRules;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetPrototypeRules;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetTypePermission;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ParameterRegistries;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken\BrokenFieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken\BrokenPrototypeRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * An editor save's operations as JSON, one object each, its kind named by
 * "op": `{"op": "renameField", "type": "shop:boat", "old": "length", "new":
 * "size"}`. A default is in stored form; a missing "default" key gives none,
 * `"default": null` pins null. A converter or initializer is its registered
 * identifier and its parameters: `{"kind": "coconut-cms:text-to-number",
 * "to": "int", "onFailure": "default"}`. A validator rule is its registered
 * identifier and its parameters as "config": `{"rule":
 * "coconut-cms:max-length", "config": {"max": 80}}`.
 */
final readonly class OperationForm
{
    /** @param ParameterRegistries $registries What a rule's parameters name registered classes through, field rules included. */
    public function __construct(
        private FieldForm $fields,
        private ConversionRegistry $conversions,
        private PrototypeValidatorRegistry $prototypeRules,
        private ParameterRegistries $registries,
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
            'renameTypes' => ['renames'],
            'renameFields' => ['type', 'renames'],
            'deleteType' => ['type'],
            'reparent' => ['type', 'parent', 'initializer'],
            'replaceType' => ['type', 'with', 'initializers', 'converters', 'resets'],
            'addField' => ['type', 'field', 'default', 'initializer'],
            'removeField', 'makeRequired', 'makeOptional' => ['type', 'field'],
            'moveField' => ['type', 'field', 'to', 'default', 'initializer'],
            'copyValues' => ['type', 'from', 'to'],
            'addUniqueGroup', 'removeUniqueGroup' => ['type', 'fields'],
            'retypeField' => ['type', 'field', 'converter', 'default'],
            'setDefault' => ['type', 'path', 'value'],
            'inheritDefault' => ['type', 'path'],
            'setFieldRules' => ['type', 'field', 'rules'],
            'setPrototypeRules' => ['type', 'rules'],
            'setFieldPermission' => ['type', 'field', 'read', 'write'],
            'setTypePermission' => ['type', 'read', 'create', 'update', 'delete'],
            default => throw InvalidInput::at("$path.op", sprintf('No operation "%s". Known: createType, renameType, renameTypes, renameFields, deleteType, reparent, replaceType, addField, removeField, renameField, moveField, copyValues, makeRequired, makeOptional, retypeField, setDefault, inheritDefault, addUniqueGroup, removeUniqueGroup, setFieldRules, setPrototypeRules, setFieldPermission, setTypePermission.', $op)),
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
            'renameTypes' => new RenameTypes(self::renames($data['renames'] ?? null, "$path.renames")),
            'renameFields' => new RenameFields($string('type'), self::renames($data['renames'] ?? null, "$path.renames")),
            'deleteType' => new DeleteType($string('type')),
            'reparent' => new Reparent(
                $string('type'),
                Json::optionalString($data, 'parent', $path),
                isset($data['initializer']) ? $this->levelInitializer($data['initializer'], "$path.initializer") : null,
            ),
            'addField' => new AddField(
                $string('type'),
                $this->fields->read($data['field'] ?? null, "$path.field"),
                self::defaultOf($data, 'default'),
                isset($data['initializer']) ? $this->initializer($data['initializer'], "$path.initializer") : null,
            ),
            'removeField' => new RemoveField($string('type'), $string('field')),
            'copyValues' => new CopyValues($string('type'), $string('from'), $string('to')),
            'replaceType' => new ReplaceType(
                $string('type'),
                $string('with'),
                $this->byField($data['initializers'] ?? [], "$path.initializers", $this->initializer(...)),
                $this->byField($data['converters'] ?? [], "$path.converters", $this->converter(...)),
                isset($data['resets']) ? self::names($data['resets'], "$path.resets") : [],
            ),
            'addUniqueGroup' => new AddUniqueGroup($string('type'), ...self::names($data['fields'] ?? null, "$path.fields")),
            'removeUniqueGroup' => new RemoveUniqueGroup($string('type'), ...self::names($data['fields'] ?? null, "$path.fields")),
            'renameField' => new RenameField($string('type'), $string('old'), $string('new')),
            'moveField' => new MoveField(
                $string('type'),
                $string('field'),
                $string('to'),
                self::defaultOf($data, 'default'),
                isset($data['initializer']) ? $this->initializer($data['initializer'], "$path.initializer") : null,
            ),
            'makeRequired' => new MakeRequired($string('type'), $string('field')),
            'makeOptional' => new MakeOptional($string('type'), $string('field')),
            'retypeField' => new RetypeField(
                $string('type'),
                $this->fields->read($data['field'] ?? null, "$path.field"),
                isset($data['converter']) ? $this->converter($data['converter'], "$path.converter") : null,
                self::defaultOf($data, 'default'),
            ),
            'setDefault' => new SetDefault($string('type'), $string('path'), self::defaultOf($data, 'value') ?? throw InvalidInput::at("$path.value", 'Missing.')),
            'inheritDefault' => new InheritDefault($string('type'), $string('path')),
            'setFieldRules' => new SetFieldRules($string('type'), $string('field'), ...$this->rules($data['rules'] ?? null, "$path.rules", $this->fieldRule(...))),
            'setPrototypeRules' => new SetPrototypeRules($string('type'), ...$this->rules($data['rules'] ?? null, "$path.rules", $this->prototypeRule(...))),
            'setFieldPermission' => new SetFieldPermission(
                $string('type'),
                $string('field'),
                Json::optionalString($data, 'read', $path),
                Json::optionalString($data, 'write', $path),
            ),
            'setTypePermission' => new SetTypePermission(
                $string('type'),
                Json::optionalString($data, 'read', $path),
                Json::optionalString($data, 'create', $path),
                Json::optionalString($data, 'update', $path),
                Json::optionalString($data, 'delete', $path),
            ),
            default => throw new LogicException(sprintf('"%s" was checked as known.', $op)),
        };
    }

    /**
     * $data as a list of rules, each `{"rule": identifier, "config": {...}}`
     * read by $readOne at its own path.
     * @template T
     * @param callable(mixed, string): T $readOne
     * @return list<T>
     */
    private function rules(mixed $data, string $path, callable $readOne): array {
        if (!is_array($data) || !array_is_list($data)) {
            throw InvalidInput::at($path, 'A list of rules is needed here: [] for none.');
        }

        $rules = [];
        foreach ($data as $index => $entry) {
            $rules[] = $readOne($entry, "$path.$index");
        }

        return $rules;
    }

    /**
     * $data as a field rule. One that can't be built is kept broken when it
     * carries "broken", as schema.get gives it: the operation then takes it
     * only where that same rule is stored.
     */
    private function fieldRule(mixed $data, string $path): FieldValidatorRule {
        try {
            return $this->registries->fieldRules()->build($data, $this->registries);
        } catch (InvalidParameters $refused) {
            if (is_array($data) && is_string($data['broken'] ?? null)) {
                return new BrokenFieldRule(array_diff_key($data, ['broken' => true]), $refused->getMessage(), true);
            }

            throw InvalidInput::at(Json::at($path, $refused->parameter ?? 'rule'), $refused->getMessage());
        }
    }

    /** $data as a cross-field rule; one that can't be built as fieldRule() says. */
    private function prototypeRule(mixed $data, string $path): PrototypeValidatorRule {
        try {
            return $this->prototypeRules->build($data, $this->registries);
        } catch (InvalidParameters $refused) {
            if (is_array($data) && is_string($data['broken'] ?? null)) {
                return new BrokenPrototypeRule(array_diff_key($data, ['broken' => true]), $refused->getMessage(), true);
            }

            throw InvalidInput::at(Json::at($path, $refused->parameter ?? 'rule'), $refused->getMessage());
        }
    }

    /**
     * $data as an object keyed by field name, each value read by $read.
     * @template T
     * @param callable(mixed, string): T $readOne Reads one value, at its path.
     * @return array<string, T>
     */
    private function byField(mixed $data, string $path, callable $readOne): array {
        $fields = $data === [] ? [] : Json::object($data, $path);
        $byField = [];
        foreach ($fields as $field => $each) {
            $byField[(string) $field] = $readOne($each, Json::at($path, (string) $field));
        }

        return $byField;
    }

    /**
     * $data as an object of renames, each old name's new one, at $path: at
     * least one.
     * @return array<string, string>
     */
    private static function renames(mixed $data, string $path): array {
        $renames = Json::object($data, $path);
        if ($renames === [] || array_filter($renames, 'is_string') !== $renames) {
            throw InvalidInput::at($path, 'An object of renames is needed here: each old name\'s new one.');
        }

        return array_combine(array_map('strval', array_keys($renames)), $renames);
    }

    /**
     * $data as a list of field names, at $path.
     * @return list<string>
     */
    private static function names(mixed $data, string $path): array {
        if (!is_array($data) || !array_is_list($data) || $data === [] || array_filter($data, 'is_string') !== $data) {
            throw InvalidInput::at($path, 'A list of field names is needed here.');
        }

        return $data;
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

    private function levelInitializer(mixed $data, string $path): LevelInitializer {
        [$identifier, $parameters] = self::conversionOf($data, $path);
        try {
            return $this->conversions->levelInitializer($identifier, $parameters);
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
            $operation instanceof RenameTypes => ['op' => 'renameTypes', 'renames' => (object) $operation->renames],
            $operation instanceof RenameFields => ['op' => 'renameFields', 'type' => $operation->type, 'renames' => (object) $operation->renames],
            $operation instanceof DeleteType => ['op' => 'deleteType', 'type' => $operation->type],
            $operation instanceof ReplaceType => [
                'op' => 'replaceType', 'type' => $operation->type, 'with' => $operation->with,
                'initializers' => (object) array_map($this->conversionForm(...), $operation->initializers),
                'converters' => (object) array_map($this->conversionForm(...), $operation->converters),
                'resets' => $operation->resets,
            ],
            $operation instanceof Reparent => ['op' => 'reparent', 'type' => $operation->type, 'parent' => $operation->parent]
                + ($operation->initializer === null ? [] : ['initializer' => $this->conversionForm($operation->initializer)]),
            $operation instanceof AddField => ['op' => 'addField', 'type' => $operation->type, 'field' => $this->fields->write($operation->field)]
                + self::defaultForm('default', $operation->default)
                + ($operation->initializer === null ? [] : ['initializer' => $this->conversionForm($operation->initializer)]),
            $operation instanceof RemoveField => ['op' => 'removeField', 'type' => $operation->type, 'field' => (string) $operation->field],
            $operation instanceof CopyValues => ['op' => 'copyValues', 'type' => $operation->type, 'from' => (string) $operation->from, 'to' => (string) $operation->to],
            $operation instanceof AddUniqueGroup => ['op' => 'addUniqueGroup', 'type' => $operation->type, 'fields' => $operation->fields],
            $operation instanceof RemoveUniqueGroup => ['op' => 'removeUniqueGroup', 'type' => $operation->type, 'fields' => $operation->fields],
            $operation instanceof RenameField => ['op' => 'renameField', 'type' => $operation->type, 'old' => (string) $operation->old, 'new' => (string) $operation->new],
            $operation instanceof MoveField => ['op' => 'moveField', 'type' => $operation->type, 'field' => (string) $operation->field, 'to' => $operation->to]
                + self::defaultForm('default', $operation->default)
                + ($operation->initializer === null ? [] : ['initializer' => $this->conversionForm($operation->initializer)]),
            $operation instanceof MakeRequired => ['op' => 'makeRequired', 'type' => $operation->type, 'field' => (string) $operation->field],
            $operation instanceof MakeOptional => ['op' => 'makeOptional', 'type' => $operation->type, 'field' => (string) $operation->field],
            $operation instanceof RetypeField => ['op' => 'retypeField', 'type' => $operation->type, 'field' => $this->fields->write($operation->field)]
                + ($operation->converter === null ? [] : ['converter' => $this->conversionForm($operation->converter)])
                + self::defaultForm('default', $operation->default),
            $operation instanceof SetDefault => ['op' => 'setDefault', 'type' => $operation->type, 'path' => $operation->path, 'value' => $operation->value->value],
            $operation instanceof InheritDefault => ['op' => 'inheritDefault', 'type' => $operation->type, 'path' => $operation->path],
            $operation instanceof SetFieldRules => [
                'op' => 'setFieldRules', 'type' => $operation->type, 'field' => (string) $operation->field,
                'rules' => array_map(fn (FieldValidatorRule $rule): array => $this->registries->fieldRules()->entryOf($rule, $this->registries), $operation->rules),
            ],
            $operation instanceof SetPrototypeRules => [
                'op' => 'setPrototypeRules', 'type' => $operation->type,
                'rules' => array_map(fn (PrototypeValidatorRule $rule): array => $this->prototypeRules->entryOf($rule, $this->registries), $operation->rules),
            ],
            $operation instanceof SetFieldPermission => ['op' => 'setFieldPermission', 'type' => $operation->type, 'field' => (string) $operation->field, 'read' => $operation->read, 'write' => $operation->write],
            $operation instanceof SetTypePermission => [
                'op' => 'setTypePermission', 'type' => $operation->type,
                'read' => $operation->read, 'create' => $operation->create, 'update' => $operation->update, 'delete' => $operation->delete,
            ],
            default => throw new LogicException(sprintf('%s has no JSON form.', $operation::class)),
        };
    }

    /** @return array<string, mixed> $default under $key; nothing for none. */
    private static function defaultForm(string $key, ?DefaultValue $default): array {
        return $default === null ? [] : [$key => $default->value];
    }

    /** @return array<string, mixed> */
    private function conversionForm(FieldConverter|FieldInitializer|LevelInitializer $conversion): array {
        [$identifier, $parameters] = $this->conversions->describe($conversion) ?? throw new LogicException(sprintf('%s is registered by no module: it has no JSON form.', $conversion::class));

        return ['kind' => $identifier, ...$parameters];
    }
}
