<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use ReflectionClass;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\LoadedEntity;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeObjectBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Loads entities as PHP objects, built from what EntityReader reads. An
 * editor-created entity loads as its last native class, its native view:
 * the IdentityMap keeps its real type. One with no native ancestor can't
 * load here. Owned objects load with their owner. A Reference loads as a lazy ghost of
 * its target's concrete class, whose fields are read only when first used.
 * One object per id: loading an id already in the IdentityMap gives that
 * object. A field its reader may not read stays unset; its stored value waits
 * in HiddenValues for the save.
 *
 * load() takes a standalone entity's id only: an owned object is reached
 * through its owner, or through a Reference. A Reference may point at any
 * entity, owned by another aggregate or not, and reaches it as itself: its
 * own type decides whether the reader may read it. An entity of a type the
 * reader may not read is refused: load() throws PermissionDenied, and a
 * Reference to it holds a ghost whose id can be read and whose first other
 * use throws. One entity is one object, however it was reached: an owned
 * object a Reference reached first is the one its owner holds.
 */
final class NativeEntityLoader
{
    private readonly NativeObjectBuilder $builder;

    /** @var array<string, string> Each referenced id's concrete identifier, looked up while loading. */
    private array $referencedIdentifiers = [];

    /** @var array<string, string> Each id seen whose root type the reader may not read, with that type. */
    private array $denied = [];

    /**
     * @param NativeViewClasses $classes Turns a stored identifier into the class to build.
     * @param Permissions|null $permissions What the reader may read: a type it
     *        may not is refused; a field it may not stays unset, its value kept
     *        in $hidden. Null for everything.
     */
    public function __construct(
        private readonly EntityReader $reader,
        private readonly NativeViewClasses $classes,
        private readonly IdentityMap $identities,
        private readonly ?Permissions $permissions = null,
        private readonly ?HiddenValues $hidden = null,
    ) {
        $readable = $permissions === null ? null : static fn (ReflectionProperty $property): bool => $permissions->canRead(
            NativeIdentifiers::of($property->getDeclaringClass()->getName()),
            $property->getName(),
        );
        $this->builder = new NativeObjectBuilder($classes, $this->referenceOf(...), $readable);
    }

    /**
     * The entity with $id, or null when there's none. Throws PermissionDenied
     * for one the reader may not read, and LogicException for one whose type
     * has no native class, its own or an ancestor's.
     */
    public function load(string $id): ?object {
        if (isset($this->denied[$id])) {
            throw PermissionDenied::ofType($this->denied[$id], TypeAction::Read);
        }

        $known = $this->identities->objectOf($id);
        if ($known !== null) {
            return $known;
        }

        $owned = $this->reader->isOwned($id);
        if ($owned === null) {
            return null;
        }

        if ($owned) {
            throw new LogicException(sprintf('Entity %s is owned by another entity: load its owner, or reach it through a Reference.', $id));
        }

        $loaded = $this->reader->read($id) ?? throw new LogicException(sprintf('Entity %s was deleted while loading it.', $id));
        if ($this->permissions !== null && !$this->permissions->may($loaded->identifier, TypeAction::Read)) {
            $this->denied[$id] = $loaded->identifier;
            throw PermissionDenied::ofType($loaded->identifier, TypeAction::Read);
        }

        if ($this->classes->lastNativeClassOf($loaded->identifier) === null) {
            throw new LogicException(sprintf('Entity %s is a %s, which has no native class, its own or an ancestor\'s: load it with loadDynamic().', $id, $loaded->identifier));
        }

        return $this->build(null, $loaded);
    }

    /** Builds $loaded, into $into if given: a lazy ghost being initialized. */
    private function build(?object $into, LoadedEntity $loaded): object {
        $this->lookUpReferencedIdentifiers($loaded->referencedIds);

        $object = $this->builder->build($this->classes->classOf($loaded->identifier), $loaded->tree, $into, function (FieldPath $path, object $owned, string $identifier) use ($loaded): void {
            $ownedId = $loaded->ownedIds[(string) $path] ?? null;
            if ($ownedId !== null) {
                $this->identities->remember($owned, $ownedId, $identifier);
                IdProperty::assign($owned, $ownedId);
            }
        }, function (object $holder, string $name, FieldPath $path, ?ValueNode $node) use ($loaded): void {
            // The owned objects the hidden value holds keep their ids, by
            // their path from the field.
            $ownedIds = [];
            foreach ($loaded->ownedIds as $ownedPath => $ownedId) {
                $relative = self::relativeTo((string) $ownedPath, (string) $path);
                if ($relative !== null) {
                    $ownedIds[$relative] = $ownedId;
                }
            }

            $this->hidden?->hide($holder, $name, $node, $ownedIds);
        }, function (FieldPath $path) use ($loaded): ?object {
            // A Reference may have reached this owned object first.
            $ownedId = $loaded->ownedIds[(string) $path] ?? null;

            return $ownedId === null ? null : $this->identities->objectOf($ownedId);
        });

        $this->identities->remember($object, $loaded->id, $loaded->identifier);
        IdProperty::assign($object, $loaded->id);

        return $object;
    }

    /**
     * The object a Reference holds: one already known, or a lazy ghost of the
     * target's concrete class. Null when the target no longer exists.
     */
    private function referenceOf(string $declaredClass, string $id): ?object {
        $known = $this->identities->objectOf($id);
        if ($known !== null) {
            return $known;
        }

        $identifier = $this->referencedIdentifiers[$id] ?? null;
        if ($identifier === null) {
            return null;
        }

        $class = $this->classes->classOf($identifier);
        $denied = $this->denied[$id] ?? null;
        $ghost = (new ReflectionClass($class))->newLazyGhost(function (object $ghost) use ($id, $denied): void {
            if ($denied !== null) {
                throw PermissionDenied::ofType($denied, TypeAction::Read);
            }

            $loaded = $this->reader->read($id) ?? throw new LogicException(sprintf('Entity %s was deleted before its reference was first used.', $id));
            $this->build($ghost, $loaded);
        });
        $this->identities->remember($ghost, $id, $identifier);
        // Readable without loading the rest of it.
        IdProperty::of($class)?->setRawValueWithoutLazyInitialization($ghost, $id);

        return $ghost;
    }

    /** $path relative to $field when it's $field or under it: "", ".1", ":spare"; null otherwise. */
    private static function relativeTo(string $path, string $field): ?string {
        if ($path === $field) {
            return '';
        }

        foreach ([FieldPath::TRAVERSE, FieldPath::EMBED] as $join) {
            if (str_starts_with($path, $field . $join)) {
                return substr($path, strlen($field));
            }
        }

        return null;
    }

    /**
     * Looks up the concrete identifier of every id in $ids not known yet, in
     * one query: a ghost must be created as its final class. Notes those the
     * reader may not read: a Reference reaches its target as itself, so the
     * target's own type decides, owned or not.
     * @param list<string> $ids
     */
    private function lookUpReferencedIdentifiers(array $ids): void {
        $unknown = array_values(array_filter(
            $ids,
            fn (string $id): bool => !isset($this->referencedIdentifiers[$id]) && $this->identities->objectOf($id) === null,
        ));

        $found = $this->reader->identifiersOf($unknown);
        $this->referencedIdentifiers = [...$this->referencedIdentifiers, ...$found];

        foreach ($found as $id => $identifier) {
            if ($this->permissions !== null && !$this->permissions->may($identifier, TypeAction::Read)) {
                $this->denied[$id] = $identifier;
            }
        }
    }
}
