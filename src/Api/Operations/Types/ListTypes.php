<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;

/**
 * Every entity type the caller may read, native or editor-created, as the
 * database holds it now, with the schema revision they're at. A type the
 * caller may not read isn't listed.
 */
#[Operation('coconut-cms:types.list')]
final readonly class ListTypes
{
    public function run(ListTypesInput $input, OperationContext $context): TypeList {
        $schemas = $context->session->schemas();
        $permissions = $context->session->permissions();

        $identifiers = $schemas->identifiers();
        sort($identifiers, SORT_STRING);

        $types = [];
        foreach ($identifiers as $identifier) {
            if ($permissions->may($identifier, TypeAction::Read)) {
                $types[] = TypeDescription::of($schemas->get($identifier), $permissions, $context->session->defaultsOf($identifier));
            }
        }

        return new TypeList($types, $context->session->schemaRevision());
    }
}
