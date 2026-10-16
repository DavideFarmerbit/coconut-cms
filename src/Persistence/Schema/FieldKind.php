<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

/**
 * The six field kinds a FieldDescriptor can represent. Mirrors the six native
 * field-kind attributes (#[Scalar], #[ValueObject], #[Embed], #[Reference],
 * #[OwningReference], #[Collection]) one-to-one.
 */
enum FieldKind: string
{
    case Scalar = 'scalar';
    case ValueObject = 'value_object';
    case Embed = 'embed';
    case Reference = 'reference';
    case OwningReference = 'owning_reference';
    case Collection = 'collection';
}
