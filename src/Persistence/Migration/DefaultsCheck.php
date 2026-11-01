<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\EntityValidator;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Runs a type's rules on its default instance, as a save would run them on
 * an entity left at its defaults, against schemas and defaults not stored
 * yet: what a migration report and an editor save report warn about.
 */
final readonly class DefaultsCheck
{
    public function __construct(
        private SchemaRegistry $registry,
        private SchemaFieldValueStore $values,
    ) {
    }

    /**
     * Every rule each of $identifiers' default instances breaks.
     * @param array<string, EntitySchema> $schemas Every schema as it will be, keyed by identifier: read ahead of the registry.
     * @param CompiledBatch $batch The new schemas and defaults.
     * @param list<string> $identifiers The types to check.
     * @return list<DefaultBreak>
     */
    public function of(array $schemas, CompiledBatch $batch, array $identifiers): array {
        $target = [...$schemas, ...$batch->schemas];
        $lookup = new SchemaLookup($this->registry, $target);
        $resolver = (new FieldValueResolver($this->registry, $this->values))->withBatch($target, $batch->values);
        $flattener = new ValueFlattener($lookup);
        $validator = new EntityValidator($lookup);

        $breaks = [];
        foreach ($identifiers as $identifier) {
            $dense = $flattener->flatten($identifier, $resolver->resolveTree(Subject::of($identifier)));
            foreach ($validator->errorsOf(new EntityValues($identifier, $dense, FieldValues::empty())) as $path => $errors) {
                foreach ($errors as $error) {
                    $breaks[] = new DefaultBreak($identifier, (string) $path, $error);
                }
            }
        }

        return $breaks;
    }
}
