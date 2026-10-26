<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use ReflectionClass;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\LoadedEntity;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeObjectBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Loads native entities as PHP objects, built from what EntityReader reads.
 * Owned objects load with their owner. A Reference loads as a lazy ghost of
 * its target's concrete class, whose fields are read only when first used.
 * One object per id: loading an id already in the IdentityMap gives that
 * object. A field its reader may not read stays unset; its stored value waits
 * in HiddenValues for the save.
 */
final class NativeEntityLoader
{
    private readonly NativeObjectBuilder $builder;

    /** @var array<string, string> Each referenced id's concrete identifier, looked up while loading. */
    private array $referencedIdentifiers = [];

    /**
     * @param NativeEntityRegistry $classes Turns a stored identifier into the class to build.
     * @param Permissions|null $permissions What the reader may read: a field it
     *        may not stays unset, its value kept in $hidden. Null for everything.
     */
    public function __construct(
        private readonly EntityReader $reader,
        private readonly NativeEntityRegistry $classes,
        private readonly IdentityMap $identities,
        ?Permissions $permissions = null,
        private readonly ?HiddenValues $hidden = null,
    ) {
        $readable = $permissions === null ? null : static fn (ReflectionProperty $property): bool => $permissions->canRead(
            NativeIdentifiers::of($property->getDeclaringClass()->getName()),
            $property->getName(),
        );
        $this->builder = new NativeObjectBuilder($classes, $this->referenceOf(...), $readable);
    }

    /** The entity with $id, or null when there's none. */
    public function load(string $id): ?object {
        $known = $this->identities->objectOf($id);
        if ($known !== null) {
            return $known;
        }

        $loaded = $this->reader->read($id);

        return $loaded === null ? null : $this->build(null, $loaded);
    }

    /** Builds $loaded, into $into if given: a lazy ghost being initialized. */
    private function build(?object $into, LoadedEntity $loaded): object {
        $this->lookUpReferencedIdentifiers($loaded->referencedIds);

        $object = $this->builder->build($this->classes->classOf($loaded->identifier), $loaded->tree, $into, function (FieldPath $path, object $owned) use ($loaded): void {
            $ownedId = $loaded->ownedIds[(string) $path] ?? null;
            if ($ownedId !== null) {
                $this->identities->remember($owned, $ownedId);
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
        });

        $this->identities->remember($object, $loaded->id);
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
        $ghost = (new ReflectionClass($class))->newLazyGhost(function (object $ghost) use ($id): void {
            $loaded = $this->reader->read($id) ?? throw new LogicException(sprintf('Entity %s was deleted before its reference was first used.', $id));
            $this->build($ghost, $loaded);
        });
        $this->identities->remember($ghost, $id);
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
     * one query: a ghost must be created as its final class.
     * @param list<string> $ids
     */
    private function lookUpReferencedIdentifiers(array $ids): void {
        $unknown = array_values(array_filter(
            $ids,
            fn (string $id): bool => !isset($this->referencedIdentifiers[$id]) && $this->identities->objectOf($id) === null,
        ));

        $this->referencedIdentifiers = [...$this->referencedIdentifiers, ...$this->reader->identifiersOf($unknown)];
    }
}
