<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConstructorParameter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\RegisteredConversion;

/**
 * Every converter and initializer the modules register, with what each fits
 * and its parameters: what the schema editor offers for a field change.
 * Given what a field holds now and will hold, only those fitting the change.
 */
#[Operation('coconut-cms:schema.conversions')]
final readonly class ListConversions
{
    public function run(ListConversionsInput $input, OperationContext $context): ConversionList {
        $persistence = $context->persistence;
        $fields = new FieldForm($persistence->enums(), $persistence->valueObjects());
        $from = $input->from === null ? null : $fields->read($input->from, 'from', 'field');
        $to = $input->to === null ? null : $fields->read($input->to, 'to', 'field');
        $signatures = new SignatureForm($persistence->valueObjects());

        $converters = [];
        $initializers = [];
        foreach ($persistence->conversions()->all() as $conversion) {
            if (!$conversion->fits($from, $to) || ($from !== null && !$conversion->isConverter())) {
                continue;
            }

            $description = self::describe($conversion, $signatures);
            if ($conversion->isConverter()) {
                $converters[] = $description;
            } else {
                $initializers[] = $description;
            }
        }

        return new ConversionList($converters, $initializers);
    }

    private static function describe(RegisteredConversion $conversion, SignatureForm $signatures): ConversionDescription {
        return new ConversionDescription(
            $conversion->identifier,
            $conversion->from === null ? null : $signatures->write($conversion->from),
            $signatures->write($conversion->to),
            array_map(static fn (ConstructorParameter $parameter): ParameterDescription => ParameterDescription::of($parameter), $conversion->shape->parameters),
        );
    }
}
