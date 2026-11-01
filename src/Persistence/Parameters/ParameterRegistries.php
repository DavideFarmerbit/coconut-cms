<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;

/**
 * What ConstructorShape names registered classes through: the enums a
 * RegisteredEnum parameter names, and the field rules a FieldRules parameter
 * holds.
 */
final readonly class ParameterRegistries
{
    /** @param FieldValidatorRegistry|null $fieldRules Null where no parameter can hold rules: a converter's. */
    public function __construct(
        public EnumRegistry $enums,
        private ?FieldValidatorRegistry $fieldRules = null,
    ) {
    }

    /** The field rules registry. Throws when there is none here. */
    public function fieldRules(): FieldValidatorRegistry {
        return $this->fieldRules ?? throw new LogicException('No field rules can be named here.');
    }
}
