<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

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
        private ?ScalarType $enumBacking = null,
        private ?string $target = null,
        private ?self $itemDescriptor = null,
        private bool $indexable = false,
        private ?Nullability $nullability = null,
        private ?FieldIdentity $identity = null,
    ) {
    }

    /**
     * A built-in primitive value: string, int, bool, and so on.
     * @param string $name The field's own name.
     * @param ScalarType $type Which primitive the field holds.
     * @param Nullability $nullability Whether it may hold null.
     * @param string|null $enumClass The backed enum class the field holds. Required
     *        when $type is ScalarType::Enum, and invalid for every other $type.
     * @param ScalarType|null $enumBacking What the enum is backed by: ScalarType::Int
     *        or ScalarType::String. Recorded here, so nothing past reading a
     *        class needs the enum itself to know its column, and a change to it
     *        shows up as a schema change. Required with $enumClass.
     * @return self
     */
    public static function scalar(string $name, ScalarType $type, Nullability $nullability, ?string $enumClass = null, ?ScalarType $enumBacking = null): self {
        if ($type === ScalarType::Enum) {
            if ($enumClass === null || $enumBacking === null) {
                throw new InvalidArgumentException('ScalarType::Enum requires $enumClass and $enumBacking.');
            }

            if ($enumBacking !== ScalarType::Int && $enumBacking !== ScalarType::String) {
                throw new InvalidArgumentException('An enum is backed by ScalarType::Int or ScalarType::String.');
            }
        } elseif ($enumClass !== null || $enumBacking !== null) {
            throw new InvalidArgumentException('$enumClass and $enumBacking only apply to ScalarType::Enum.');
        }

        return new self(FieldKind::Scalar, $name, scalarType: $type, enumClass: $enumClass, enumBacking: $enumBacking, nullability: $nullability);
    }

    /**
     * A custom primitive type: exactly one column, with no identity of its own and
     * never usable as an Entity.
     * @param string $name The field's own name.
     * @param string $type The value object class, implementing StorableValue: it
     *        stores and rebuilds itself.
     * @param bool $indexable What $type's own indexable() said when this was
     *        compiled: recorded here, so a change to it shows up as a schema change.
     * @param Nullability $nullability Whether it may hold null.
     * @return self
     */
    public static function valueObject(string $name, string $type, bool $indexable, Nullability $nullability): self {
        return new self(FieldKind::ValueObject, $name, target: $type, indexable: $indexable, nullability: $nullability);
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
     * Always Nullable: deleting its target nulls it.
     * @param string $name The field's own name.
     * @param string $type The referenced shape's identifier.
     * @return self
     */
    public static function reference(string $name, string $type): self {
        return new self(FieldKind::Reference, $name, target: $type, nullability: Nullability::Nullable);
    }

    /**
     * An Owned, singular pointer to another entity: no column of its own, found
     * through the owned entity's own owner/owner_field instead of a foreign key.
     * @param string $name The field's own name.
     * @param string $type The owned shape's identifier.
     * @param Nullability $nullability Whether the slot may be empty.
     * @return self
     */
    public static function owningReference(string $name, string $type, Nullability $nullability): self {
        return new self(FieldKind::OwningReference, $name, target: $type, nullability: $nullability);
    }

    /**
     * A type, as Unreal's TSubclassOf: the identifier of $base or of a type
     * extending it, native or editor-made. Stored as that identifier, and
     * rewritten by the migrations renaming or replacing the type it names.
     * @param string $name The field's own name.
     * @param string $base The type every value is, or extends.
     * @param Nullability $nullability Whether it may hold no type.
     * @return self
     */
    public static function typeReference(string $name, string $base, Nullability $nullability): self {
        return new self(FieldKind::TypeReference, $name, target: $base, nullability: $nullability);
    }

    /**
     * A collection of items, ordered by position. Whether the collection is Owned or
     * Shared rides entirely on $itemDescriptor's own kind (owningReference() versus
     * reference()).
     * @param string $name The field's own name.
     * @param self $itemDescriptor The item's own descriptor, built from one of this
     *        class's other factories. Never another collection(), which would
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

    /** Valid for FieldKind::Scalar only; non-null exactly when scalarType() is ScalarType::Enum. */
    public function enumBacking(): ?ScalarType {
        $this->assertKind(FieldKind::Scalar);

        return $this->enumBacking;
    }

    /** Valid for ValueObject, Embed, Reference, OwningReference and TypeReference: for a TypeReference, its base type. */
    public function target(): string {
        $validKinds = [FieldKind::ValueObject, FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference, FieldKind::TypeReference];
        if (!in_array($this->kind, $validKinds, true)) {
            throw new LogicException(sprintf('target() is not valid for FieldKind::%s.', $this->kind->name));
        }

        return $this->target;
    }

    /** Valid for FieldKind::ValueObject only: whether its stored string always fits in 255 characters. */
    public function indexable(): bool {
        $this->assertKind(FieldKind::ValueObject);

        return $this->indexable;
    }

    /** Valid for FieldKind::Collection only. */
    public function itemDescriptor(): self {
        $this->assertKind(FieldKind::Collection);

        return $this->itemDescriptor;
    }

    /** Valid for Scalar, ValueObject, Reference, OwningReference and TypeReference: an Embed and a Collection are never null. */
    public function nullability(): Nullability {
        if ($this->nullability === null) {
            throw new LogicException(sprintf('nullability() is not valid for FieldKind::%s.', $this->kind->name));
        }

        return $this->nullability;
    }

    /** Whether null is a valid value here: never for an Embed or a Collection. */
    public function allowsNull(): bool {
        return $this->nullability?->allowsNull() ?? false;
    }

    /**
     * Its own nullability, or its items' for a Collection: what
     * withNullability() changes. Null when there's none: an Embed, or a
     * Collection of Embeds.
     */
    public function ownOrItemNullability(): ?Nullability {
        return $this->kind === FieldKind::Collection ? $this->itemDescriptor->nullability : $this->nullability;
    }

    /**
     * Its id and column; null for a field not stored yet: the code's and a
     * draft's fields have none, and a Collection's item never has.
     */
    public function identity(): ?FieldIdentity {
        return $this->identity;
    }

    /** Whether $other declares the same field: where each is stored doesn't count. */
    public function sameAs(self $other): bool {
        return $this->withIdentity(null) == $other->withIdentity(null);
    }

    /** Whether $other is this field with only its own or its items' nullability changed, or not at all. */
    public function differsOnlyInNullability(self $other): bool {
        if ($this->kind !== $other->kind || $this->itemDescriptor?->kind !== $other->itemDescriptor?->kind) {
            return false;
        }

        $nullability = $other->ownOrItemNullability();
        if ($nullability === null || $this->ownOrItemNullability() === null) {
            return $this->sameAs($other);
        }

        return $this->withNullability($nullability)->sameAs($other);
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

    /*================================================================================================================*/
    // Copies

    /** The same field under another name, stored where it was: a Collection's item is renamed with it. */
    public function renamed(string $name): self {
        return new self(
            $this->kind,
            $name,
            $this->scalarType,
            $this->enumClass,
            $this->enumBacking,
            $this->target,
            $this->itemDescriptor?->renamed($name),
            $this->indexable,
            $this->nullability,
            $this->identity,
        );
    }

    /** The same field, stored as $identity says; null for a field not stored. */
    public function withIdentity(?FieldIdentity $identity): self {
        return new self(
            $this->kind,
            $this->name,
            $this->scalarType,
            $this->enumClass,
            $this->enumBacking,
            $this->target,
            $this->itemDescriptor,
            $this->indexable,
            $this->nullability,
            $identity,
        );
    }

    /**
     * The same field, pointing at $to wherever it pointed at the entity type
     * $from: its own target, or its Collection item's. A value object's target
     * is a class, never an entity type, so it never changes.
     */
    public function retargeted(string $from, string $to): self {
        return $this->retargetedAll([$from => $to]);
    }

    /**
     * The same field, pointing at each entity type `$renames` maps its target
     * to, all at once: two types swapping names swap what points at them.
     * A value object's target is a class, never an entity type, so it never
     * changes.
     * @param array<string, string> $renames Each old identifier's new one.
     */
    public function retargetedAll(array $renames): self {
        $retargets = $this->kind !== FieldKind::ValueObject && $this->target !== null && isset($renames[$this->target]);

        return new self(
            $this->kind,
            $this->name,
            $this->scalarType,
            $this->enumClass,
            $this->enumBacking,
            $retargets ? $renames[$this->target] : $this->target,
            $this->itemDescriptor?->retargetedAll($renames),
            $this->indexable,
            $this->nullability,
            $this->identity,
        );
    }

    /**
     * The same field with $nullability: its own, or its items' for a
     * Collection. Throws for a kind that has none, and for a Reference made
     * NotNullable.
     */
    public function withNullability(Nullability $nullability): self {
        if ($this->kind === FieldKind::Collection) {
            return self::collection($this->name, $this->itemDescriptor->withNullability($nullability))->withIdentity($this->identity);
        }

        $this->nullability();
        if ($this->kind === FieldKind::Reference && !$nullability->allowsNull()) {
            throw new InvalidArgumentException('A Reference is always Nullable: deleting its target nulls it.');
        }

        return new self(
            $this->kind,
            $this->name,
            $this->scalarType,
            $this->enumClass,
            $this->enumBacking,
            $this->target,
            null,
            $this->indexable,
            $nullability,
            $this->identity,
        );
    }

    // ~Copies
    /*================================================================================================================*/
}
