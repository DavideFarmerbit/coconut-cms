<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * The field kinds a FieldDescriptor can represent. Mirrors the native
 * field-kind attributes (#[Scalar], #[ValueObject], #[Embed], #[Reference],
 * #[OwningReference], #[TypeReference], #[Collection]) one-to-one. What
 * adding one takes is in the storage architecture, "Adding a field kind".
 */
enum FieldKind: string
{
    case Scalar = 'scalar';
    case ValueObject = 'value_object';
    case Embed = 'embed';
    case Reference = 'reference';
    case OwningReference = 'owning_reference';
    case TypeReference = 'type_reference';
    case Collection = 'collection';
}
