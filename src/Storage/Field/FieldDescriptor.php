<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use InvalidArgumentException;

/**
 * Describes one field of an entity or value object shape: its kind, storage tier, and
 * constraints. Neutral to where the shape came from, a native class via reflection or
 * an editor-assembled definition, both build the same FieldDescriptor.
 *
 * Built through the named factories below, never the constructor directly, so an
 * invalid combination (a Choice field with no options, a required-unique field that
 * isn't queryable) can't be constructed at all.
 */
final readonly class FieldDescriptor
{
    /**
     * @param FieldValidator[] $validators
     * @param string|null $referencedShape the referenced entity or value object class, for Embed/Reference/Collection
     * @param FieldKind|null $collectionItemKind the kind of each item, for Collection only
     * @param mixed[]|null $choiceOptions the allowed values, for Choice only
     * @param Ownership|null $ownership Shared or Owned, for Reference and EntityReference Collection only
     */
    private function __construct(
        public string $name,
        public FieldKind $kind,
        public string $label,
        public ?string $group,
        public bool $queryable,
        public bool $unique,
        public array $validators,
        public ?string $referencedShape = null,
        public ?FieldKind $collectionItemKind = null,
        public ?array $choiceOptions = null,
        public ?Ownership $ownership = null,
    ) {
    }

    /**
     * A plain String/Int/Float/Bool field. `unique` forces `queryable` to true
     * regardless of what was passed, since a UNIQUE constraint needs a real column.
     *
     * @param FieldValidator[] $validators
     */
    public static function scalar(
        string $name,
        FieldKind $kind,
        string $label,
        ?string $group = null,
        bool $queryable = false,
        bool $unique = false,
        array $validators = [],
    ): self {
        if (!in_array($kind, [FieldKind::String, FieldKind::Int, FieldKind::Float, FieldKind::Bool], true)) {
            throw new InvalidArgumentException(sprintf('FieldDescriptor::scalar() does not accept kind %s.', $kind->name));
        }

        return new self(
            name: $name,
            kind: $kind,
            label: $label,
            group: $group,
            queryable: $queryable || $unique,
            unique: $unique,
            validators: $validators,
        );
    }

    /**
     * A field restricted to one of a fixed set of options.
     *
     * @param mixed[] $options the allowed values, must not be empty
     * @param FieldValidator[] $validators
     */
    public static function choice(
        string $name,
        array $options,
        string $label,
        ?string $group = null,
        bool $queryable = false,
        array $validators = [],
    ): self {
        if ($options === []) {
            throw new InvalidArgumentException(sprintf('FieldDescriptor "%s" needs at least one choice option.', $name));
        }

        return new self(
            name: $name,
            kind: FieldKind::Choice,
            label: $label,
            group: $group,
            queryable: $queryable,
            unique: false,
            validators: $validators,
            choiceOptions: $options,
        );
    }

    /**
     * A value object inlined into the owner's own storage. Never its own column,
     * never queryable as a whole, since the container itself isn't a column.
     *
     * @param FieldValidator[] $validators
     */
    public static function embed(
        string $name,
        string $shapeClass,
        string $label,
        ?string $group = null,
        array $validators = [],
    ): self {
        return new self(
            name: $name,
            kind: FieldKind::EmbeddedValueObject,
            label: $label,
            group: $group,
            queryable: false,
            unique: false,
            validators: $validators,
            referencedShape: $shapeClass,
        );
    }

    /**
     * A reference to another entity. Always a real FK column regardless of
     * `queryable`/`unique`, those only control indexing/filtering on a column that
     * exists either way.
     *
     * @param FieldValidator[] $validators
     */
    public static function reference(
        string $name,
        string $shapeClass,
        Ownership $ownership,
        string $label,
        ?string $group = null,
        bool $queryable = false,
        bool $unique = false,
        array $validators = [],
    ): self {
        return new self(
            name: $name,
            kind: FieldKind::EntityReference,
            label: $label,
            group: $group,
            queryable: $queryable,
            unique: $unique,
            validators: $validators,
            referencedShape: $shapeClass,
            ownership: $ownership,
        );
    }

    /**
     * A repeated list of values. EntityReference items always get a real join table
     * (Shared) or a dedicated child table (Owned) regardless of `queryable`.
     * EmbeddedValueObject/scalar items live in the JSON blob instead, always
     * non-queryable, filtering an outer list by a value inside it is out of scope.
     *
     * @param FieldValidator[] $validators
     */
    public static function collection(
        string $name,
        FieldKind $itemKind,
        ?string $referencedShape,
        string $label,
        ?string $group = null,
        ?Ownership $ownership = null,
        array $validators = [],
    ): self {
        if ($itemKind === FieldKind::EntityReference && ($referencedShape === null || $ownership === null)) {
            throw new InvalidArgumentException(sprintf('Collection "%s" of EntityReference needs both a referencedShape and an ownership.', $name));
        }

        return new self(
            name: $name,
            kind: FieldKind::Collection,
            label: $label,
            group: $group,
            queryable: false,
            unique: false,
            validators: $validators,
            referencedShape: $referencedShape,
            collectionItemKind: $itemKind,
            ownership: $ownership,
        );
    }
}
