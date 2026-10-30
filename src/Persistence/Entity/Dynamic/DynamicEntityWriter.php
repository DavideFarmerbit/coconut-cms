<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\AfterEdit;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\EditRefused;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\HiddenValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\UnseenValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeFields;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeValueReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Saves a DynamicEntity with everything it owns, as its real type: the
 * fields its native view's class declares read from the view, the rest from
 * its bag. An owned object in a view stood for by a DynamicEntity takes its
 * other fields from that one's bag; one nobody stood for keeps them as
 * stored, by id, the way a native view's save does. An owned object only a
 * bag holds is written from its DynamicEntity.
 *
 * Before anything is read, afterEdit() runs on every view of the aggregate
 * whose DynamicEntity set() changed: the deepest first, each told the paths
 * set on it. Then the values are read once the aggregate is locked, and
 * written. Ids are handed out and remembered as for a native save, once the
 * write succeeded.
 */
final class DynamicEntityWriter
{
    private readonly NativeValueReader $reader;

    /** @var array<string, object> What's owned at each path of the aggregate written: a native object or a DynamicEntity. */
    private array $owned = [];

    /** @var array<string, string> The id kept at each path: owned objects inside values a load hid, or a bag can't show its reader. */
    private array $keptIds = [];

    public function __construct(
        private readonly EntityWriter $writer,
        private readonly NativeEntityValues $values,
        private readonly DynamicEntities $entities,
        private readonly IdentityMap $identities,
        private readonly SchemaLookup $schemas,
        private readonly FieldValueResolver $resolver,
        private readonly ?HiddenValues $hidden = null,
    ) {
        $this->reader = new NativeValueReader($values->referenceIdOf(...));
    }

    /**
     * Saves $entity and everything it owns.
     * @return string Its id: the one it had, or a new one.
     * @throws ValidationFailed When afterEdit() or a validator refuses it.
     */
    public function save(DynamicEntity $entity): string {
        $changed = $this->changedIn($entity);
        $this->runAfterEdit($changed, $entity->type());

        $id = $entity->id() ?? EntityIds::generate();
        $unseen = null;
        $written = [];
        $deleted = $this->writer->writeWith($id, function (?StoredEntity $stored) use ($entity, $id, &$unseen): EntityValues {
            $unseen = new UnseenValues($this->identities, $this->schemas, $this->resolver, $id, $stored);
            $this->owned = [];
            $this->keptIds = [];

            return $this->values->fromTree($entity->type(), $this->treeOf($entity, null, FieldPath::TRAVERSE, $unseen));
        }, function (FieldPath $path) use (&$unseen, &$written): string {
            $held = $this->owned[(string) $path] ?? null;
            $ownedId = match (true) {
                $held instanceof DynamicEntity => $held->id(),
                $held !== null => $this->identities->idOf($held),
                default => $this->keptIds[(string) $path] ?? $unseen?->keptIdAt($path),
            } ?? EntityIds::generate();
            if ($held !== null) {
                $written[] = [$held, $ownedId];
            }

            return $ownedId;
        });

        foreach ($deleted as $deletedId) {
            $object = $this->identities->objectOf($deletedId);
            $this->identities->forget($deletedId);
            if ($object !== null) {
                IdProperty::assign($object, null);
            }
        }

        foreach ([[$entity, $id], ...$written] as [$held, $heldId]) {
            $this->remember($held, $heldId);
        }

        foreach ($changed as [$changedEntity]) {
            $changedEntity->clearChanged();
        }

        return $id;
    }

    /** Remembers $held, a DynamicEntity or a native object, as entity $id, its view with it. */
    private function remember(object $held, string $id): void {
        if ($held instanceof DynamicEntity) {
            $held->saved($id);
            $this->entities->rememberSaved($held);
            $view = $held->view();
            if ($view !== null) {
                $this->identities->remember($view, $id, $held->type());
                IdProperty::assign($view, $id);
            }

            return;
        }

        $this->identities->remember($held, $id);
        IdProperty::assign($held, $id);
    }

    /**
     * $entity's whole tree, as its real type, sitting at $at in the
     * aggregate, joined to its fields by $join; null $at for the entity saved.
     */
    private function treeOf(DynamicEntity $entity, ?string $at, string $join, ?UnseenValues $unseen): ObjectNode {
        $pathOf = static fn (FieldPath $path): string => $at === null ? (string) $path : $at . $join . $path;
        $view = $entity->view();
        $fields = $view === null ? ObjectNode::empty() : $this->reader->read(
            $view,
            function (FieldPath $path, object $owned) use ($pathOf): void {
                $this->owned[$pathOf($path)] = $owned;
            },
            $this->hidden === null ? null : function (object $holder, string $name, FieldPath $path) use ($pathOf): ?ValueNode {
                [$node, $ownedIds] = $this->hidden->of($holder, $name);
                foreach ($ownedIds as $relative => $ownedId) {
                    $this->keptIds[$pathOf($path) . $relative] = $ownedId;
                }

                return $node;
            },
            fn (object $owned, ObjectNode $ownedFields, FieldPath $path): OwnedNode => $this->completed($owned, $ownedFields, $pathOf($path), $unseen),
        );

        return $this->withBag($entity, $fields, $at, $join, $unseen);
    }

    /**
     * An owned object a view holds, at $at, as its real type: what its
     * DynamicEntity's bag holds, or what's stored for it when nobody stood
     * for it.
     */
    private function completed(object $owned, ObjectNode $fields, string $at, ?UnseenValues $unseen): OwnedNode {
        $standing = $this->entities->standingFor($owned);
        if ($standing !== null) {
            return new OwnedNode($standing->type(), $this->withBag($standing, $fields, $at, FieldPath::TRAVERSE, $unseen));
        }

        return $unseen?->complete($owned, $fields, FieldPath::fromString($at))
            ?? new OwnedNode($this->identities->typeOf($owned) ?? NativeIdentifiers::of($owned::class), $fields);
    }

    /** $fields, with every field of $entity's bag. */
    private function withBag(DynamicEntity $entity, ObjectNode $fields, ?string $at, string $join, ?UnseenValues $unseen): ObjectNode {
        foreach ($entity->bagFields() as $name => [$field, $value, $isKept, $stored]) {
            $path = $at === null ? $name : $at . $join . $name;
            if ($isKept) {
                // Its reader may not read it, and never set it: kept as stored, the owned objects in it with their ids.
                foreach ($entity->heldIdsAt($name) as $relative => $ownedId) {
                    $this->keptIds[$at === null ? $relative : $at . $join . $relative] = $ownedId;
                }
                $fields = $fields->with(FieldName::of($name), $stored);
                continue;
            }

            $fields = $fields->with(FieldName::of($name), $this->nodeOf($field, $value, $path, FieldPath::EMBED, $unseen));
        }

        return $fields;
    }

    /**
     * $value, in the form get() gives, at $path, in stored form.
     * @param string $embedJoin How an Embed held here joins its fields: ":" singular, "." as an item.
     */
    private function nodeOf(FieldDescriptor $field, mixed $value, string $path, string $embedJoin, ?UnseenValues $unseen): ?ValueNode {
        return match ($field->kind()) {
            FieldKind::Scalar => new LeafNode(ScalarValues::toStorage($field->scalarType(), $value)),
            FieldKind::ValueObject => new LeafNode($value === null ? null : StorableValues::toStorage($value)),
            FieldKind::Reference => new LeafNode($value),
            FieldKind::Embed => $this->treeOf($value, $path, $embedJoin, $unseen),
            FieldKind::OwningReference => $value === null ? null : $this->ownedNodeOf($value, $path, $unseen),
            FieldKind::Collection => new CollectionNode(array_map(
                fn (int $index): ?ValueNode => $this->nodeOf($field->itemDescriptor(), $value[$index], $path . FieldPath::TRAVERSE . $index, FieldPath::TRAVERSE, $unseen),
                array_keys($value),
            )),
        };
    }

    /** The owned object $held, a bag holds at $path. */
    private function ownedNodeOf(DynamicEntity $held, string $path, ?UnseenValues $unseen): OwnedNode {
        $this->owned[$path] = $held;

        return new OwnedNode($held->type(), $this->treeOf($held, $path, FieldPath::TRAVERSE, $unseen));
    }

    /**
     * Every DynamicEntity of the aggregate whose set() changed it, with its
     * path and depth: in its bag, in its view, in the native objects between.
     * @return list<array{DynamicEntity, string, int}>
     */
    private function changedIn(DynamicEntity $entity): array {
        $found = [];
        $this->visit($entity, '', 0, $found);

        return array_values(array_filter($found, static fn (array $each): bool => $each[0]->changed() !== []));
    }

    /**
     * Visits $held, a DynamicEntity or a native object at $at, and the
     * Embeds and owned objects it holds, never what a Reference points at:
     * that's another aggregate.
     * @param list<array{DynamicEntity, string, int}> $found
     */
    private function visit(object $held, string $at, int $depth, array &$found): void {
        $entity = $held instanceof DynamicEntity ? $held : $this->entities->standingFor($held);
        $view = $held instanceof DynamicEntity ? $held->view() : $held;
        if ($entity !== null) {
            $found[] = [$entity, $at, $depth];
            foreach ($entity->bagFields() as $name => [$field, $value]) {
                $this->visitHeld($field->kind() === FieldKind::Collection ? $field->itemDescriptor()->kind() : $field->kind(), $value, self::joined($at, $name), $depth + 1, $found);
            }
        }

        if ($view === null) {
            return;
        }

        foreach (NativeFields::of(new ReflectionClass($view)) as $property) {
            if (!$property->isInitialized($view)) {
                continue;
            }

            $kind = NativeFields::kindAttributesOf($property)[0]->newInstance();
            $held = match (true) {
                $kind instanceof Embed => FieldKind::Embed,
                $kind instanceof OwningReference => FieldKind::OwningReference,
                $kind instanceof Collection => $kind->itemKind,
                default => null,
            };
            if ($held !== null) {
                $this->visitHeld($held, $property->getValue($view), self::joined($at, $property->getName()), $depth + 1, $found);
            }
        }
    }

    /**
     * Visits $value, or each of its items, when $kind says it holds Embeds
     * or owned objects.
     * @param list<array{DynamicEntity, string, int}> $found
     */
    private function visitHeld(FieldKind $kind, mixed $value, string $at, int $depth, array &$found): void {
        if ($kind !== FieldKind::Embed && $kind !== FieldKind::OwningReference) {
            return;
        }

        foreach (is_array($value) ? $value : [$value] as $index => $item) {
            if (is_object($item)) {
                $this->visit($item, is_array($value) ? $at . FieldPath::TRAVERSE . $index : $at, $depth, $found);
            }
        }
    }

    /**
     * Calls afterEdit() on the view of each of $changed implementing it, the
     * deepest first, given the paths set on it.
     * @param list<array{DynamicEntity, string, int}> $changed
     * @param string $type The saved entity's type: what a refusal is about.
     * @throws ValidationFailed When one refuses the edit.
     */
    private function runAfterEdit(array $changed, string $type): void {
        usort($changed, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
        foreach ($changed as [$entity, $at]) {
            $view = $entity->view();
            if (!$view instanceof AfterEdit) {
                continue;
            }

            try {
                $view->afterEdit($entity->changed());
            } catch (EditRefused $refused) {
                throw new ValidationFailed($type, [$at => [$refused->getMessage()]]);
            }
        }
    }

    /** $name under $at: a path from the entity saved. */
    private static function joined(string $at, string $name): string {
        return $at === '' ? $name : $at . FieldPath::TRAVERSE . $name;
    }
}
