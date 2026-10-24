<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;

/**
 * One hash for everything about the code a migration depends on: every
 * native type's fingerprint, and every step declared. A migration stores the
 * key of the code it migrated to; while the code's key is a different one, a
 * migration is pending. Step ids count because a step can move data with no
 * schema change at all.
 */
final class StateKey
{
    /** $batch's types and $steps, as one key: order doesn't matter. */
    public static function of(CompiledBatch $batch, MigrationSteps $steps): string {
        $fingerprints = [];
        foreach ($batch->stored as $each) {
            $fingerprints[$each->schema->identifier] = $each->fingerprint;
        }
        ksort($fingerprints, SORT_STRING);

        $stepIds = $steps->ids();
        sort($stepIds, SORT_STRING);

        return hash('sha256', json_encode(['types' => $fingerprints, 'steps' => $stepIds], JSON_THROW_ON_ERROR));
    }
}
