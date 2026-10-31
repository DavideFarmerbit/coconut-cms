<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * One converter or initializer a module registered: its identifier, what it
 * fits, and the parameters a save gives it. What the editor lists to offer.
 */
final readonly class RegisteredConversion
{
    /**
     * @param class-string<FieldConverter>|class-string<FieldInitializer>|class-string<LevelInitializer> $class
     * @param FieldSignature|null $from The fields a converter takes; null for an initializer.
     * @param FieldSignature|null $to The fields a converter gives, or a field
     *        initializer fills; null for a level initializer, written for the
     *        types it fills.
     */
    public function __construct(
        public string $identifier,
        public ConversionKind $kind,
        public string $class,
        public ?FieldSignature $from,
        public ?FieldSignature $to,
        public ConstructorShape $shape,
    ) {
    }

    /**
     * Whether it fits a field changing from $old to $new: a converter both,
     * a field initializer $new alone. A field left null fits anything. A
     * level initializer fits no field change.
     */
    public function fits(?FieldDescriptor $old, ?FieldDescriptor $new): bool {
        if ($this->kind === ConversionKind::LevelInitializer) {
            return $old === null && $new === null;
        }

        if ($old !== null && $this->kind !== ConversionKind::Converter) {
            return false;
        }

        return ($new === null || $this->to->matches($new))
            && ($old === null || $this->from->matches($old));
    }
}
