<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * Finds a compiled EntitySchema by identifier: the one already in memory this
 * request, otherwise the one SchemaStore holds, otherwise nothing. Never
 * compiles: SchemaCompiler is the only thing that ever produces a schema, and
 * registers it here once it's committed.
 */
final class SchemaRegistry
{
    /** @var array<string, EntitySchema> */
    private array $schemas = [];

    public function __construct(
        private readonly SchemaStore $store,
    ) {
    }

    /** For a schema just committed: from now on, served from memory. */
    public function register(EntitySchema $schema): void {
        $this->schemas[$schema->identifier] = $schema;
    }

    /**
     * Forgets every schema held in memory: after a migration commits, or
     * rolls back, what's in memory may not be what's stored.
     */
    public function forgetAll(): void {
        $this->schemas = [];
    }

    /** True for a stored schema even when it's stale: get() is what checks that. */
    public function has(string $identifier): bool {
        return array_key_exists($identifier, $this->schemas) || $this->store->load($identifier) !== null;
    }

    /**
     * Every type known: stored, or registered this request.
     * @return list<string>
     */
    public function identifiers(): array {
        return array_values(array_unique([...$this->store->identifiers(), ...array_keys($this->schemas)]));
    }

    /**
     * @throws InvalidArgumentException When nothing is stored for $identifier.
     * @throws StaleSchemaException When a stored schema was compiled against a
     *         dependency that has changed since.
     */
    public function get(string $identifier): EntitySchema {
        if (isset($this->schemas[$identifier])) {
            return $this->schemas[$identifier];
        }

        $stored = $this->store->load($identifier) ?? throw new InvalidArgumentException(sprintf(
            '"%s" has never been compiled.',
            $identifier,
        ));

        foreach ($stored->dependencies as $dependency => $fingerprint) {
            if ($this->store->load($dependency)?->fingerprint !== $fingerprint) {
                throw new StaleSchemaException($identifier, $dependency);
            }
        }

        return $this->schemas[$identifier] = $stored->schema;
    }
}
