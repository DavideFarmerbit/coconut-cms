<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use InvalidArgumentException;
use LogicException;

/**
 * Describes one field of an entity shape, independent of whether the shape is a native
 * PHP class or an editor-created prototype. Both sources produce the same FieldDescriptor
 * shape; the rest of the persistence layer only ever reads this, never the original
 * source.
 */
final readonly class FieldDescriptor
{
    private function __construct(
        private FieldKind $kind,
        private string $name,
        private ?ScalarType $scalarType = null,
        private ?string $enumClass = null,
        private ?string $target = null,
        private bool $required = false,
        private ?self $itemDescriptor = null,
    ) {
    }

    /**
     * A built-in primitive value: string, int, bool, and so on.
     * @param string $name The field's own name.
     * @param ScalarType $type Which primitive the field holds.
     * @param string|null $enumClass The backed enum class the field holds. Required
     *        when $type is ScalarType::Enum, and invalid for every other $type.
     * @return self
     */
    public static function scalar(string $name, ScalarType $type, ?string $enumClass = null): self {
        if ($type === ScalarType::Enum) {
            if ($enumClass === null) {
                throw new InvalidArgumentException('ScalarType::Enum requires $enumClass.');
            }
        } elseif ($enumClass !== null) {
            throw new InvalidArgumentException('$enumClass only applies to ScalarType::Enum.');
        }

        return new self(FieldKind::Scalar, $name, scalarType: $type, enumClass: $enumClass);
    }

    /**
     * A custom primitive type: exactly one column, with no identity of its own and
     * never usable as an Entity.
     * @param string $name The field's own name.
     * @param string $type The custom Type class converting this field's value to and
     *        from the database, the same shape as a Doctrine custom Type.
     * @return self
     */
    public static function valueObject(string $name, string $type): self {
        return new self(FieldKind::ValueObject, $name, target: $type);
    }

    /**
     * Reuses an Entity-capable shape at this one field site, flattening the embedded
     * shape's own fields into the owner's row as real columns.
     * @param string $name The field's own name; also the column prefix the embedded
     *        shape's own fields flatten under.
     * @param string $type The embedded shape's identifier.
     * @return self
     */
    public static function embed(string $name, string $type): self {
        return new self(FieldKind::Embed, $name, target: $type);
    }

    /**
     * A Shared, singular pointer to another entity: a real, always-nullable foreign
     * key column on this field's own side, never required at the schema level.
     * @param string $name The field's own name.
     * @param string $type The referenced shape's identifier.
     * @return self
     */
    public static function reference(string $name, string $type): self {
        return new self(FieldKind::Reference, $name, target: $type);
    }

    /**
     * An Owned, singular pointer to another entity: no column of its own, found
     * through the owned entity's own owner/owner_field instead of a foreign key.
     * @param string $name The field's own name.
     * @param string $type The owned shape's identifier.
     * @param bool $required Whether an absent slot gets backfilled with a defaulted
     *        instance of $type, rather than staying empty.
     * @return self
     */
    public static function owningReference(string $name, string $type, bool $required): self {
        return new self(FieldKind::OwningReference, $name, target: $type, required: $required);
    }

    /**
     * A collection of items, ordered by position. Whether the collection is Owned or
     * Shared rides entirely on $itemDescriptor's own kind (owningReference() versus
     * reference()).
     * @param string $name The field's own name.
     * @param self $itemDescriptor The item's own descriptor, built from one of this
     *        class's other five factories. Never another collection(), which would
     *        make the cardinality ambiguous.
     * @return self
     */
    public static function collection(string $name, self $itemDescriptor): self {
        if ($itemDescriptor->kind === FieldKind::Collection) {
            throw new InvalidArgumentException('A Collection item descriptor cannot itself be a Collection.');
        }

        return new self(FieldKind::Collection, $name, itemDescriptor: $itemDescriptor);
    }

    /*================================================================================================================*/
    // Accessors

    public function kind(): FieldKind {
        return $this->kind;
    }

    public function name(): string {
        return $this->name;
    }

    /** Valid for FieldKind::Scalar only. */
    public function scalarType(): ScalarType {
        $this->assertKind(FieldKind::Scalar);

        return $this->scalarType;
    }

    /** Valid for FieldKind::Scalar only; non-null exactly when scalarType() is ScalarType::Enum. */
    public function enumClass(): ?string {
        $this->assertKind(FieldKind::Scalar);

        return $this->enumClass;
    }

    /** Valid for ValueObject, Embed, Reference, and OwningReference. */
    public function target(): string {
        $validKinds = [FieldKind::ValueObject, FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference];
        if (!in_array($this->kind, $validKinds, true)) {
            throw new LogicException(sprintf('target() is not valid for FieldKind::%s.', $this->kind->name));
        }

        return $this->target;
    }

    /** Valid for FieldKind::OwningReference only. */
    public function required(): bool {
        $this->assertKind(FieldKind::OwningReference);

        return $this->required;
    }

    /** Valid for FieldKind::Collection only. */
    public function itemDescriptor(): self {
        $this->assertKind(FieldKind::Collection);

        return $this->itemDescriptor;
    }

    private function assertKind(FieldKind $expected): void {
        if ($this->kind !== $expected) {
            throw new LogicException(sprintf(
                'Expected FieldKind::%s, got FieldKind::%s.',
                $expected->name,
                $this->kind->name,
            ));
        }
    }

    // ~Accessors
    /*================================================================================================================*/
}
