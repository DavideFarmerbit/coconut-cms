<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * Rebuilds the blueprint of a type already compiled and stored, whatever its
 * source: its stored schema, and only the default values it sets itself. How a
 * type with no class to read, an editor-created one, is compiled again: by a
 * native migration whose types it depends on, or by an editor save that
 * leaves it as it is. What it inherits follows its ancestors as compiled in
 * the same batch.
 */
final readonly class StoredBlueprintReader
{
    public function __construct(
        private SchemaStore $schemas,
        private SchemaFieldValueStore $values,
    ) {
    }

    /** Throws when nothing is stored for $identifier. */
    public function read(string $identifier): EntityBlueprint {
        $stored = $this->schemas->load($identifier) ?? throw new InvalidArgumentException(sprintf('"%s" has never been compiled.', $identifier));

        return self::of($stored->schema, $stored->source, $this->values->load($identifier));
    }

    /**
     * Every stored type of $source.
     * @return array<string, EntityBlueprint> Keyed by identifier.
     */
    public function readAll(SchemaSource $source): array {
        $blueprints = [];
        foreach ($this->schemas->all() as $stored) {
            if ($stored->source === $source) {
                $blueprints[$stored->schema->identifier] = self::of($stored->schema, $source, $this->values->load($stored->schema->identifier));
            }
        }

        return $blueprints;
    }

    /**
     * The blueprint of $schema, setting $own itself: for a stored schema a
     * migration's fixups changed before it's compiled again.
     * @param FieldValues $own The default values the type sets itself, in stored form.
     */
    public static function of(EntitySchema $schema, SchemaSource $source, FieldValues $own): EntityBlueprint {
        return new EntityBlueprint(
            identifier: $schema->identifier,
            source: $source,
            parent: $schema->parent,
            table: $schema->table,
            editorExtensible: $schema->editorExtensible,
            fields: $schema->fields,
            fieldPermissions: $schema->fieldPermissions,
            fieldValidators: $schema->fieldValidators,
            uniqueGroups: $schema->uniqueGroups,
            prototypeValidators: $schema->prototypeValidators,
            defaults: BlueprintDefaults::own($own),
            typePermission: $schema->typePermission,
        );
    }
}
