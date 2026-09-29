<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;
use XyloIsCoding\CoconutCms\Storage\Schema\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\Query\Query;

/**
 * Hands out one Repository per identifier (a native class-string or an editor-created
 * prototype's own name), sharing one Connection and one IdentityMap across all of them.
 * A Repository asks this to resolve a reference or collection field into the
 * referenced identifier's own Repository, so a Tag reached from a Product goes through
 * the exact same identity-mapped lookup a direct Tag query would.
 *
 * Also the front door to PrototypeRegistry for shape/instantiation, so Repository and
 * ChangesetFlusher never call PrototypeShape directly and never need their own
 * native-vs-editor-created branch.
 */
final class EntityManager
{
    private readonly IdentityMap $identityMap;
    private readonly PrototypeRegistry $registry;

    /** @var array<string, Repository> */
    private array $repositories = [];

    /**
     * @param array<string, string> $tables identifier => table name, every level of a
     *   chain needs its own entry, not just the leaf, for both native and
     *   editor-created identifiers
     * @param PrototypeRegistry|null $registry shares one instance with SchemaEditor's
     *   own, if a caller already has one; defaults to a fresh one built from the same
     *   connection, safe since PrototypeRegistry is stateless, every method reads the
     *   database directly
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $tables,
        ?PrototypeRegistry $registry = null,
    ) {
        $this->identityMap = new IdentityMap();
        $this->registry = $registry ?? new PrototypeRegistry($connection);
    }

    public function repository(string $identifier): Repository
    {
        return $this->repositories[$identifier] ??= new Repository($this->connection, $this->identityMap, $this, $identifier, $this->tables);
    }

    /** Filters, sorts, and cursor-paginates $identifier's own chain, see Query. */
    public function query(string $identifier): Query
    {
        return new Query($this->connection, $this, $identifier);
    }

    public function tableOf(string $identifier): string
    {
        return $this->tables[$identifier] ?? throw new LogicException(sprintf('No table registered for "%s".', $identifier));
    }

    /** The id a previously find()/insert()-ed instance was registered under, if any, across every identifier this manager handles. */
    public function idOf(object $instance): ?string
    {
        return $this->identityMap->idOf($instance);
    }

    /** @return string[] base first, ending with $identifier itself */
    public function chainOf(string $identifier): array
    {
        return $this->registry->chainOf($identifier);
    }

    public function isEditorCreated(string $identifier): bool
    {
        return $this->registry->isEditorCreated($identifier);
    }

    /** @return FieldDescriptor[] only the fields declared at this exact level */
    public function ownFieldsOf(string $identifier): array
    {
        return $this->registry->ownFieldsOf($identifier);
    }

    /** @return FieldDescriptor[] the full effective shape, every level's own fields concatenated, base first */
    public function fieldsOf(string $identifier): array
    {
        return $this->registry->fieldsOf($identifier);
    }

    /** @return PrototypeValidator[] every cross-field rule across the whole chain */
    public function prototypeValidatorsOf(string $identifier): array
    {
        return $this->registry->prototypeValidatorsOf($identifier);
    }

    /**
     * Turns a resolved values array into a live instance for $identifier, native or
     * editor-created, see PrototypeRegistry::instantiate().
     *
     * @param array<string, mixed> $values
     */
    public function instantiate(string $identifier, array $values): object
    {
        return $this->registry->instantiate($identifier, $values);
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
