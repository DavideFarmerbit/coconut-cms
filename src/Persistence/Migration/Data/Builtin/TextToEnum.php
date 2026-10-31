<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use BackedEnum;
use InvalidArgumentException;
use ReflectionEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\RegisteredEnum;

/**
 * A text field turned into an Enum one, for RetypeField: each text becomes
 * the case backed by it, "red" as Color::Red; for an int-backed enum, "2" as
 * the case backed by 2. Text no case is backed by does what $onFailure
 * says. Null stays null; a value following the old default follows the new
 * one.
 */
#[Converter('coconut-cms:text-to-enum')]
final readonly class TextToEnum implements FieldConverter
{
    /** @param class-string<BackedEnum> $enum Given by its registered identifier in a save. */
    public function __construct(
        #[RegisteredEnum]
        public string $enum,
        public OnFailure $onFailure,
    ) {
        if (!is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException(sprintf('TextToEnum converts to a backed enum, and "%s" is none.', $enum));
        }
    }

    public function convert(Captured $old): Conversion {
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

        $backing = $this->backingOf($value);
        $case = $backing === null ? null : $this->enum::tryFrom($backing);
        if ($case !== null) {
            return Conversion::of($case->value);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('TextToEnum', $value);
    }

    /**
     * $text as the enum's backing type: an int-backed enum's cases are found
     * by number. Null when $text is no number such an enum could be backed by.
     */
    private function backingOf(string $text): int|string|null {
        if ((string) (new ReflectionEnum($this->enum))->getBackingType() !== 'int') {
            return $text;
        }

        $number = filter_var(trim($text), FILTER_VALIDATE_INT);

        return $number === false ? null : $number;
    }
}
