<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\Registry;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\CoreModule;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnFailure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConversionRegistry;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\InvalidParameters;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\RegisteredEnum;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\ConvertsAnyField;

/** A string-backed enum registered as "test:hue". */
enum RegistryHue: string
{
    case Red = 'red';
}

/** A project's own converter: the old price text, in a currency. */
#[Converter('shop:price-in')]
final readonly class PriceIn implements FieldConverter
{
    use ConvertsAnyField;

    public function __construct(
        public string $currency,
        public int $cents = 100,
    ) {
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::of($old->value() . ' ' . $this->currency);
    }
}

/** A project's converter naming an enum: the hue a colour name had in it. */
#[Converter('shop:hue-of')]
final readonly class HueOf implements FieldConverter
{
    use ConvertsAnyField;

    /** @param class-string<\BackedEnum> $enum */
    public function __construct(
        #[RegisteredEnum]
        public string $enum,
    ) {
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::default();
    }
}

/** A converter whose parameter can't be written back: no property of its name. */
#[Converter('shop:hidden')]
final readonly class HiddenParameter implements FieldConverter
{
    use ConvertsAnyField;

    public function __construct(string $secret) {
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::default();
    }
}

/** The converters and initializers modules register, built from a save's parameters and written back. */
final class ConversionRegistryTest extends TestCase
{
    private function registry(): ConversionRegistry {
        $enums = new EnumRegistry();
        $enums->register(Identifier::of('test', 'hue'), RegistryHue::class);
        $registry = new ConversionRegistry($enums);
        foreach ((new CoreModule())->converters() as $class) {
            $registry->register($class);
        }

        $registry->register(PriceIn::class);
        $registry->register(HueOf::class);

        return $registry;
    }

    public function testABuiltInIsBuiltFromItsParametersAndWrittenBack(): void {
        $registry = $this->registry();

        $converter = $registry->converter('coconut-cms:text-to-number', ['onFailure' => 'cancel']);

        self::assertEquals(new TextToNumber(OnFailure::Cancel), $converter);
        self::assertSame(['coconut-cms:text-to-number', ['onFailure' => 'cancel']], $registry->describe($converter));
        self::assertEquals(new ConstantValue(['a' => 1]), $registry->initializer('coconut-cms:constant', ['value' => ['a' => 1]]));
    }

    public function testAnEnumIsNamedByItsIdentifier(): void {
        $registry = $this->registry();

        $converter = $registry->converter('shop:hue-of', ['enum' => 'test:hue']);

        self::assertSame(RegistryHue::class, $converter->enum);
        self::assertSame(['shop:hue-of', ['enum' => 'test:hue']], $registry->describe($converter));
        $this->expectException(InvalidParameters::class);
        $registry->converter('shop:hue-of', ['enum' => 'test:nothing']);
    }

    public function testAProjectsOwnConverterTakesItsParametersByName(): void {
        $registry = $this->registry();

        $converter = $registry->converter('shop:price-in', ['currency' => 'EUR']);

        self::assertEquals(new PriceIn('EUR', 100), $converter);
        self::assertSame(['shop:price-in', ['currency' => 'EUR', 'cents' => 100]], $registry->describe($converter));
    }

    public function testParametersThatDontFitAreRefusedNamingTheOneAtFault(): void {
        $registry = $this->registry();
        $cases = [
            [['cents' => 5], 'currency', '"currency" is missing.'],
            [['currency' => 'EUR', 'rate' => 2], 'rate', '"rate" is no parameter here.'],
            [['currency' => 12], 'currency', '"currency" can\'t take 12.'],
        ];
        foreach ($cases as [$parameters, $at, $message]) {
            try {
                $registry->converter('shop:price-in', $parameters);
                self::fail($message);
            } catch (InvalidParameters $refused) {
                self::assertSame($at, $refused->parameter);
                self::assertSame($message, $refused->getMessage());
            }
        }

        try {
            $registry->initializer('shop:price-in', []);
            self::fail('A converter is no initializer.');
        } catch (InvalidParameters $refused) {
            self::assertNull($refused->parameter);
        }
    }

    public function testAConverterMustBeWritableBack(): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs a public property of the same name');

        $this->registry()->register(HiddenParameter::class);
    }

    public function testAnIdentifierIsTakenOnce(): void {
        $this->expectException(LogicException::class);

        $this->registry()->register(PriceIn::class);
    }

    public function testAnUnregisteredOneIsNotWrittenBack(): void {
        self::assertNull((new ConversionRegistry(new EnumRegistry()))->describe(new TextToEnum(OnFailure::Default)));
    }
}
