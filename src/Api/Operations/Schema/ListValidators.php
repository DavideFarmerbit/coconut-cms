<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\FieldCodec;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SignatureCodec;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ConstructorParameter;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ConstructorShape;

/**
 * Every validator rule the modules' classes use or the modules list, with
 * what each fits and its parameters: what the schema editor offers for a
 * field or a type. Given a field, only the field rules fitting it.
 */
#[Operation('coconut-cms:schema.validators')]
final readonly class ListValidators
{
    public function run(ListValidatorsInput $input, OperationContext $context): ValidatorList {
        $persistence = $context->persistence;
        $field = $input->field === null ? null : (new FieldCodec($persistence->enums(), $persistence->valueObjects()))->read($input->field, 'field', 'field');
        $signatures = new SignatureCodec($persistence->valueObjects());

        $fieldRules = [];
        foreach ($persistence->fieldRules()->all() as $identifier => $class) {
            if ($field === null || $class::fits()->matches($field)) {
                $fieldRules[] = new RuleDescription($identifier, $signatures->write($class::fits()), self::parametersOf($class));
            }
        }

        $prototypeRules = [];
        foreach ($persistence->prototypeRules()->all() as $identifier => $class) {
            $prototypeRules[] = new RuleDescription($identifier, null, self::parametersOf($class));
        }

        return new ValidatorList($fieldRules, $prototypeRules);
    }

    /**
     * @param class-string $class
     * @return list<ParameterDescription>
     */
    private static function parametersOf(string $class): array {
        return array_map(static fn (ConstructorParameter $parameter): ParameterDescription => ParameterDescription::of($parameter), ConstructorShape::of($class)->parameters);
    }
}
