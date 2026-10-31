<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConstructorParameter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConversionKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\RegisteredConversion;

/**
 * Every converter and initializer the modules register, with what each fits
 * and its parameters: what the schema editor offers for a field change, and
 * the level initializers it offers for a reparent. Given what a field holds
 * now and will hold, only those fitting the change, and no level initializer.
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

        $listed = [ConversionKind::Converter->name => [], ConversionKind::Initializer->name => [], ConversionKind::LevelInitializer->name => []];
        foreach ($persistence->conversions()->all() as $conversion) {
            if ($conversion->fits($from, $to)) {
                $listed[$conversion->kind->name][] = self::describe($conversion, $signatures);
            }
        }

        return new ConversionList(
            $listed[ConversionKind::Converter->name],
            $listed[ConversionKind::Initializer->name],
            $listed[ConversionKind::LevelInitializer->name],
        );
    }

    private static function describe(RegisteredConversion $conversion, SignatureForm $signatures): ConversionDescription {
        return new ConversionDescription(
            $conversion->identifier,
            $conversion->from === null ? null : $signatures->write($conversion->from),
            $conversion->to === null ? null : $signatures->write($conversion->to),
            array_map(static fn (ConstructorParameter $parameter): ParameterDescription => ParameterDescription::of($parameter), $conversion->shape->parameters),
        );
    }
}
