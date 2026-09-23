<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;

/**
 * Hands out one Repository per entity class, sharing one Connection and one
 * IdentityMap across all of them. A Repository asks this to resolve a reference or
 * collection field into the referenced class's own Repository, so a Tag reached from a
 * Product goes through the exact same identity-mapped lookup a direct Tag query would.
 */
final class EntityManager
{
    private readonly IdentityMap $identityMap;

    /** @var array<class-string, Repository> */
    private array $repositories = [];

    /**
     * @param array<class-string, string> $tables entity class => table name, every
     *   level of an inheritance chain needs its own entry, not just the leaf class
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $tables,
    ) {
        $this->identityMap = new IdentityMap();
    }

    /** @param class-string $class */
    public function repository(string $class): Repository
    {
        return $this->repositories[$class] ??= new Repository($this->connection, $this->identityMap, $this, $class, $this->tables);
    }

    /**
     * Resolves reference values (ids) into the actual hydrated objects a native
     * class's constructor expects, everything else passes through unchanged. A Shared
     * collection's items resolve the same way; an Owned collection's items are already
     * real objects (they have no independent id to resolve from), same as an embed.
     *
     * A value that's already an object is left alone, not re-resolved, this is what
     * lets DraftPreview hand in a not-yet-persisted preview object (built from another
     * change in the same draft) in place of an id to look up.
     *
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function hydrateReferences(array $fields, array $values): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field->name, $values)) {
                continue;
            }

            if ($field->kind === FieldKind::EntityReference) {
                $id = $values[$field->name];
                $values[$field->name] = $id === null || is_object($id) ? $id : $this->repository($field->referencedShape)->find((string) $id);
            } elseif ($field->kind === FieldKind::Collection && $field->collectionItemKind === FieldKind::EntityReference && $field->ownership === Ownership::Shared) {
                $itemRepository = $this->repository($field->referencedShape);
                $values[$field->name] = array_map(
                    static fn (mixed $id): ?object => is_object($id) ? $id : $itemRepository->find((string) $id),
                    $values[$field->name],
                );
            }
        }

        return $values;
    }
}
