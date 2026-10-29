<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;

/** One entity type, as the database holds it now. Refused for a type the caller may not read. */
#[Operation('coconut-cms:types.get')]
final readonly class GetType
{
    public function run(GetTypeInput $input, OperationContext $context): TypeDescription {
        $schemas = $context->session->schemas();
        if (!$schemas->has($input->type)) {
            throw new NotFound('type', $input->type);
        }

        $permissions = $context->session->permissions();
        if (!$permissions->may($input->type, TypeAction::Read)) {
            throw PermissionDenied::ofType($input->type, TypeAction::Read);
        }

        return TypeDescription::of($schemas->get($input->type), $permissions, $context->session->defaultsOf($input->type));
    }
}
