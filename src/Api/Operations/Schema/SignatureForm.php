<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;

/**
 * A FieldSignature as JSON, in the keys types.get describes a field with:
 * `{"kind": "scalar", "scalarType": "int", "target": null, "item": null}`,
 * a key left null matching any; or a union, `{"oneOf": [...]}`. A value
 * object's target is its identifier.
 */
final readonly class SignatureForm
{
    public function __construct(
        private ValueObjectRegistry $valueObjects,
    ) {
    }

    /** @return array<string, mixed> */
    public function write(FieldSignature $signature): array {
        if ($signature->isUnion()) {
            return ['oneOf' => array_map($this->write(...), $signature->oneOf)];
        }

        $target = $signature->target;
        if ($target !== null && $signature->kind === FieldKind::ValueObject) {
            $target = (string) $this->valueObjects->identifierOf($target);
        }

        return [
            'kind' => $signature->kind?->value,
            'scalarType' => $signature->scalarType?->value,
            'target' => $target,
            'item' => $signature->item === null ? null : $this->write($signature->item),
        ];
    }
}
