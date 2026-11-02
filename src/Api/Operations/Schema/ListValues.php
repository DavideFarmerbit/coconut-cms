<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/**
 * Every enum and value object the modules registered: what the schema
 * editor offers a field to hold, beside the scalars and the entity types.
 * An enum comes with its values, for picking a default.
 */
#[Operation('coconut-cms:schema.values')]
final readonly class ListValues
{
    public function run(ListValuesInput $input, OperationContext $context): ValueList {
        $persistence = $context->persistence;
        $enums = [];
        foreach ($persistence->enums()->all() as $identifier => $class) {
            $enums[] = EnumDescription::of($identifier, $class);
        }

        return new ValueList($enums, array_keys($persistence->valueObjects()->all()));
    }
}
