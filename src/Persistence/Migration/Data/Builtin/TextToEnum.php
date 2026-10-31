<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use BackedEnum;
use InvalidArgumentException;
use ReflectionEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * A text field turned into an Enum one, for RetypeField, the enum the new
 * field's: each text becomes the case backed by it, "red" as Color::Red;
 * for an int-backed enum, "2" as the case backed by 2. Text no case is
 * backed by does what $onFailure says. Null stays null; a value following
 * the old default follows the new one.
 */
#[Converter('coconut-cms:text-to-enum')]
final readonly class TextToEnum implements FieldConverter
{
    public function __construct(
        public OnFailure $onFailure,
    ) {
    }

    public static function from(): FieldSignature {
        return FieldSignature::text();
    }

    public static function to(): FieldSignature {
        return FieldSignature::scalar(ScalarType::Enum);
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($value === null) {
            return Conversion::of(null);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('TextToEnum converts text, got %s.', get_debug_type($value)));
        }

        /** @var class-string<BackedEnum> $enum */
        $enum = $to->enumClass();
        $backing = self::backingOf($value, $enum);
        $case = $backing === null ? null : $enum::tryFrom($backing);
        if ($case !== null) {
            return Conversion::of($case->value);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('TextToEnum', $value);
    }

    /**
     * $text as $enum's backing type: an int-backed enum's cases are found by
     * number. Null when $text is no number such an enum could be backed by.
     * @param class-string<BackedEnum> $enum
     */
    private static function backingOf(string $text, string $enum): int|string|null {
        if ((string) (new ReflectionEnum($enum))->getBackingType() !== 'int') {
            return $text;
        }

        $number = filter_var(trim($text), FILTER_VALIDATE_INT);

        return $number === false ? null : $number;
    }
}
