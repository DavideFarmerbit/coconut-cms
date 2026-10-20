<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * Holds every EntitySchema the compiler has already produced this request, so
 * asking for the same identifier twice never recompiles it. Carries no
 * compilation logic of its own: SchemaCompiler is the only thing that ever puts a
 * schema in here.
 */
final class SchemaRegistry
{
    /** @var array<string, EntitySchema> */
    private array $schemas = [];

    public function register(EntitySchema $schema): void {
        $this->schemas[$schema->identifier] = $schema;
    }

    public function has(string $identifier): bool {
        return array_key_exists($identifier, $this->schemas);
    }

    /**
     * @throws InvalidArgumentException if the schema is not registered.
     */
    public function get(string $identifier): EntitySchema {
        return $this->schemas[$identifier] ?? throw new InvalidArgumentException(sprintf(
            '"%s" has not been compiled this request.',
            $identifier,
        ));
    }
}
