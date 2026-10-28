<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * Checks values in stored form, keyed by path the way ValueFlattener lays
 * them out, against the fields of a type: what a value written without a
 * PHP property's type behind it must pass. Used on a type's default values
 * when it's compiled, and on every value a migration moves.
 *
 * A value must be what its field stores, null only where the field allows
 * it. A Reference must be an id; whether it points at the right type is
 * checked where the entities are. An owned slot must hold the target type
 * or a descendant. A Collection's bare path holds its item count.
 */
final readonly class StoredForm
{
    public function __construct(
        private SchemaLookup $schemas,
    ) {
    }

    /**
     * $values, checked, with each value in its one canonical form: a Decimal
     * with its full scale, a Float from an int. Throws naming the first value
     * that isn't valid. A path no field has is left as it is: see
     * unknownPaths().
     * @param array<string, mixed> $values Keyed by path, for type $identifier.
     * @param list<string>|null $only The paths to check, null for all: the
     *        others still say which type each owned slot holds, and how many
     *        items each Collection has.
     * @return array<string, mixed>
     */
    public function checked(string $identifier, array $values, ?array $only = null): array {
        $checking = $only === null ? null : array_flip($only);
        foreach ($this->fieldsAt($identifier, $values) as $path => $field) {
            if (array_key_exists($path, $values) && ($checking === null || isset($checking[$path]))) {
                $values[$path] = $this->checkedValue($field, $values[$path], $path);
            }
        }

        return $values;
    }

    /**
     * The paths in $values no field of $identifier's has: a field gone, an
     * item past a Collection's count, a value at an Embed's bare path.
     * @param array<string, mixed> $values Keyed by path.
     * @return list<string>
     */
    public function unknownPaths(string $identifier, array $values): array {
        return array_values(array_map('strval', array_keys(array_diff_key($values, $this->fieldsAt($identifier, $values)))));
    }

    /**
     * Every path a value of $identifier can be at, given what $values holds
     * for owned slots and Collection counts, with the field whose value goes
     * there.
     * @param array<string, mixed> $values
     * @return array<string, FieldDescriptor>
     */
    private function fieldsAt(string $identifier, array $values): array {
        $fields = [];
        $this->collect($this->schemas->hierarchyFieldsOf($identifier), FieldPath::of(...), $values, $fields);

        return $fields;
    }

    /**
     * @param Closure(FieldName): FieldPath $pathOf
     * @param array<string, mixed> $values
     * @param array<string, FieldDescriptor> $fields Filled in.
     */
    private function collect(FieldDescriptorCollection $own, Closure $pathOf, array $values, array &$fields): void {
        foreach ($own as $name => $field) {
            $this->collectValue($field, $pathOf($name), $values, $fields);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, FieldDescriptor> $fields Filled in.
     */
    private function collectValue(FieldDescriptor $field, FieldPath $path, array $values, array &$fields): void {
        if ($field->kind() === FieldKind::Embed) {
            $this->collect($this->schemas->hierarchyFieldsOf($field->target()), $path->embed(...), $values, $fields);

            return;
        }

        $fields[(string) $path] = $field;
        $value = $values[(string) $path] ?? null;

        if ($field->kind() === FieldKind::OwningReference && is_string($value) && $this->isOrExtends($value, $field->target())) {
            $this->collect($this->schemas->hierarchyFieldsOf($value), $path->traverse(...), $values, $fields);
        }

        if ($field->kind() === FieldKind::Collection && is_int($value)) {
            $item = $field->itemDescriptor();
            for ($index = 0; $index < $value; $index++) {
                $itemPath = $path->traverse($index);
                // An Embed item's fields are traversed, never embedded: each
                // item is its own row.
                $item->kind() === FieldKind::Embed
                    ? $this->collect($this->schemas->hierarchyFieldsOf($item->target()), $itemPath->traverse(...), $values, $fields)
                    : $this->collectValue($item, $itemPath, $values, $fields);
            }
        }
    }

    /**
     * $value in canonical form, once it's valid for $field: what one value
     * given in stored form, outside an aggregate, must pass. Throws naming
     * $path.
     */
    public function checkedValue(FieldDescriptor $field, mixed $value, string $path): mixed {
        if ($value === null) {
            if ($field->kind() === FieldKind::Collection) {
                throw self::invalid($path, 'an item count', $value);
            }

            return $field->allowsNull() ? null : throw new InvalidArgumentException(sprintf('"%s" can\'t be null.', $path));
        }

        return match ($field->kind()) {
            FieldKind::Scalar => $this->checkedScalar($field, $value, $path),
            FieldKind::ValueObject => $this->checkedValueObject($field, $value, $path),
            FieldKind::Reference => is_string($value) ? $value : throw self::invalid($path, 'an entity id', $value),
            FieldKind::OwningReference => is_string($value) && $this->isOrExtends($value, $field->target())
                ? $value
                : throw self::invalid($path, sprintf('the identifier of "%s" or of a type extending it', $field->target()), $value),
            FieldKind::Collection => is_int($value) && $value >= 0 ? $value : throw self::invalid($path, 'an item count', $value),
            FieldKind::Embed => throw new InvalidArgumentException(sprintf('"%s" is an Embed: it holds no value of its own.', $path)),
        };
    }

    private function checkedScalar(FieldDescriptor $field, mixed $value, string $path): mixed {
        $type = $field->scalarType();
        $valid = match ($type) {
            ScalarType::String => is_string($value) && mb_strlen($value) <= ScalarValues::MAX_STRING_LENGTH,
            ScalarType::Text, ScalarType::Decimal => is_string($value),
            ScalarType::Int => is_int($value),
            ScalarType::Float => is_float($value) || is_int($value),
            ScalarType::Bool => is_bool($value),
            ScalarType::Date => self::isFormatted($value, ScalarValues::DATE_FORMAT),
            ScalarType::DateTime => self::isFormatted($value, ScalarValues::DATE_TIME_FORMAT),
            ScalarType::Enum => ($field->enumBacking() === ScalarType::Int ? is_int($value) : is_string($value))
                && $field->enumClass()::tryFrom($value) !== null,
        };

        if (!$valid) {
            throw self::invalid($path, self::expectedScalar($field), $value);
        }

        return match ($type) {
            ScalarType::Float => (float) $value,
            ScalarType::Decimal => self::rethrownAt($path, static fn (): mixed => ScalarValues::toStorage(ScalarType::Decimal, $value)),
            default => $value,
        };
    }

    /** A string the value object's class can rebuild itself from. */
    private function checkedValueObject(FieldDescriptor $field, mixed $value, string $path): string {
        if (!is_string($value)) {
            throw self::invalid($path, 'a string', $value);
        }

        if ($field->indexable() && mb_strlen($value) > StorableValues::MAX_INDEXABLE_LENGTH) {
            throw new InvalidArgumentException(sprintf('"%s" holds %d characters: %s is indexable, so it holds %d at most.', $path, mb_strlen($value), $field->target(), StorableValues::MAX_INDEXABLE_LENGTH));
        }

        self::rethrownAt($path, static fn (): mixed => $field->target()::fromStorage($value));

        return $value;
    }

    /** Whether $identifier is a type, and $target or one of its descendants. */
    private function isOrExtends(string $identifier, string $target): bool {
        if (!$this->schemas->has($identifier)) {
            return false;
        }

        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            if ($current === $target) {
                return true;
            }
        }

        return false;
    }

    /** Whether $value is a string written exactly in $format. */
    private static function isFormatted(mixed $value, string $format): bool {
        if (!is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $parsed !== false && $parsed->format($format) === $value;
    }

    private static function expectedScalar(FieldDescriptor $field): string {
        return match ($field->scalarType()) {
            ScalarType::String => sprintf('a string of %d characters at most', ScalarValues::MAX_STRING_LENGTH),
            ScalarType::Text => 'a string',
            ScalarType::Decimal => 'a number written as a string, like "12.50"',
            ScalarType::Int => 'an int',
            ScalarType::Float => 'a float',
            ScalarType::Bool => 'a bool',
            ScalarType::Date => sprintf('a date written "%s"', ScalarValues::DATE_FORMAT),
            ScalarType::DateTime => sprintf('a UTC date and time written "%s"', ScalarValues::DATE_TIME_FORMAT),
            ScalarType::Enum => sprintf('the backing value of a case of %s', $field->enumClass()),
        };
    }

    /**
     * What $check returns; what it throws, rethrown naming $path.
     * @param Closure(): mixed $check
     */
    private static function rethrownAt(string $path, Closure $check): mixed {
        try {
            return $check();
        } catch (Throwable $failure) {
            throw new InvalidArgumentException(sprintf('"%s": %s', $path, $failure->getMessage()), 0, $failure);
        }
    }

    private static function invalid(string $path, string $expected, mixed $value): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" must hold %s, got %s.', $path, $expected, is_scalar($value) ? var_export($value, true) : get_debug_type($value)));
    }
}
