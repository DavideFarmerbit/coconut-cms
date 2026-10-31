<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * One converter or initializer a module registered: its identifier, what it
 * fits, and the parameters a save gives it. What the editor lists to offer.
 */
final readonly class RegisteredConversion
{
    /**
     * @param class-string<FieldConverter>|class-string<FieldInitializer> $class
     * @param FieldSignature|null $from The fields a converter takes; null for an initializer.
     * @param FieldSignature $to The fields a converter gives, or an initializer fills.
     */
    public function __construct(
        public string $identifier,
        public string $class,
        public ?FieldSignature $from,
        public FieldSignature $to,
        public ConstructorShape $shape,
    ) {
    }

    /** Whether it's a converter, rather than an initializer. */
    public function isConverter(): bool {
        return $this->from !== null;
    }

    /**
     * Whether it fits a field changing from $old to $new: a converter both,
     * an initializer $new alone. A field left null fits anything.
     */
    public function fits(?FieldDescriptor $old, ?FieldDescriptor $new): bool {
        return ($new === null || $this->to->matches($new))
            && ($old === null || $this->from === null || $this->from->matches($old));
    }
}
