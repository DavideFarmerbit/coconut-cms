<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;

/**
 * One hash for everything about the code a migration depends on: every
 * native type's fingerprint, every step declared, and every registered
 * validator rule's source. A migration stores the key of the code it
 * migrated to; while the code's key is a different one, a migration is
 * pending. Step ids count because a step can move data with no schema change
 * at all. Rule sources count because a changed rule class can break a rule
 * stored on an editor-created type, which no native type shows.
 */
final class StateKey
{
    /**
     * $batch's types, $steps and $rules, as one key: order doesn't matter.
     * @param array<string, class-string> $rules Every registered rule class, as SchemaStore::ruleClasses() gives them.
     */
    public static function of(CompiledBatch $batch, MigrationSteps $steps, array $rules): string {
        $fingerprints = [];
        foreach ($batch->stored as $each) {
            $fingerprints[$each->schema->identifier] = $each->fingerprint;
        }
        ksort($fingerprints, SORT_STRING);

        $stepIds = $steps->ids();
        sort($stepIds, SORT_STRING);

        $sources = [];
        foreach ($rules as $key => $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $sources[$key] = $file === false ? $class : hash_file('sha256', $file);
        }
        ksort($sources, SORT_STRING);

        return hash('sha256', json_encode(['types' => $fingerprints, 'steps' => $stepIds, 'rules' => $sources], JSON_THROW_ON_ERROR));
    }
}
