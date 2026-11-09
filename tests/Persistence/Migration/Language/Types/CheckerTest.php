<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Language\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Builtins;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Parser;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Printer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\EnumCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\SetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\TreeCodec;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\ArrayShapes;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\CheckedStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Checker;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\InvalidStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;

/**
 * A product before and after a change: flat price and supplier fields
 * become a money Embed and an owned supplier; dimensions keep their shape.
 */
final class CheckerTest extends TestCase
{
    private static function checker(): Checker {
        $t = Parser::type(...);
        $enums = ['shop:currency' => ['EUR' => 'eur', 'USD' => 'usd'], 'shop:state' => ['draft' => 1, 'live' => 2]];
        $dimensions = ['width' => $t('decimal'), 'height' => $t('decimal')];

        $old = new ArrayShapes([
            'shop:product' => [
                'name' => $t('string'),
                'priceAmount' => $t('decimal'),
                'priceCurrency' => $t('text'),
                'supplierName' => $t('text?'),
                'stock' => $t('int'),
                'weight' => $t('float?'),
                'state' => $t('enum shop:state'),
                'size' => $t('embed shop:dimensions'),
                'tags' => $t('list text'),
                'brand' => $t('ref shop:brand?'),
            ],
            'shop:dimensions' => $dimensions,
            'shop:brand' => [],
        ], [], $enums);

        $new = new ArrayShapes([
            'shop:product' => [
                'name' => $t('string'),
                'price' => $t('embed shop:money'),
                'supplier' => $t('owns shop:party?'),
                'stock' => $t('decimal'),
                'weight' => $t('float?'),
                'state' => $t('enum shop:state'),
                'size' => $t('embed shop:dimensions'),
                'label' => $t('text'),
                'tags' => $t('list text'),
                'brand' => $t('ref shop:brand?'),
                'code' => $t('int'),
            ],
            'shop:money' => ['amount' => $t('decimal'), 'currency' => $t('enum shop:currency')],
            'shop:party' => ['name' => $t('string')],
            'shop:supplier' => ['email' => $t('text?')],
            'shop:dimensions' => $dimensions,
            'shop:brand' => [],
        ], ['shop:supplier' => 'shop:party'], $enums);

        return new Checker($old, $new, Builtins::standard());
    }

    private static function check(string $statements): CheckedStep {
        return self::checker()->check(Parser::step("data in shop:product {\n$statements\n}"));
    }

    /** @return list<string> */
    private static function problems(string $statements): array {
        try {
            self::check($statements);
        } catch (InvalidStep $invalid) {
            return $invalid->problems;
        }

        self::fail('The step checked.');
    }

    public function testAWholeChangeChecks(): void {
        $checked = self::check(<<<'TEXT'
            set new.price = {
              amount: old.priceAmount * 100,
              currency: match upper(trim(old.priceCurrency)) {
                "EUR", "€" => EUR
                "USD" => USD
                else => fail("unknown currency: " ~ old.priceCurrency)
              }
            }
            let name = trim(old.supplierName ?? "")
            set new.supplier = if name == "" then null else new shop:supplier { name: name, email: null }
            set new.stock = old.stock
            set new.size = old.size
            set new.label = old.tags.filter(t => t != "").join(", ")
            TEXT);

        self::assertSame(['priceAmount', 'priceCurrency', 'supplierName', 'stock', 'size', 'tags'], $checked->reads);
        self::assertSame(['price', 'supplier', 'stock', 'size', 'label'], $checked->writes);
    }

    public function testABareNameBecomesTheEnumCaseExpected(): void {
        $checked = self::check('set new.state = if old.stock > 0 then live else draft');
        $set = $checked->step->statements[0];
        self::assertInstanceOf(SetStatement::class, $set);

        $json = TreeCodec::encodeStep($checked->step);
        self::assertStringContainsString('{"enum":"shop:state","case":"live"}', $json);
        self::assertStringNotContainsString('"name"', $json);
        self::assertSame(2, $checked->caseValues[$set->value->then]);
        self::assertInstanceOf(EnumCase::class, $set->value->else);
    }

    public function testACheckedTreeChecksAgainToTheSame(): void {
        $checked = self::check('set new.state = if old.stock > 0 then live else draft');

        self::assertEquals($checked->step, self::checker()->check(TreeCodec::decodeStep(TreeCodec::encodeStep($checked->step)))->step);
    }

    public function testNumberLiteralsTakeTheTypeAroundThem(): void {
        $checked = self::check('set new.stock = old.priceAmount * 2 + 1');

        self::assertEquals(Type::decimal(), $checked->typeOf($checked->step->statements[0]->value));
    }

    public function testANullCheckNarrows(): void {
        self::check(<<<'TEXT'
            set new.label = if old.supplierName == null then "" else old.supplierName
            if old.weight != null and old.weight > 1 {
              set new.code = 1
            } else {
              set new.code = 2
            }
            TEXT);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mistakes(): iterable {
        yield 'a nullable value into a field never null' => ['set new.label = old.supplierName', 'may be null'];
        yield 'a nullable value in arithmetic' => ['set new.code = toInt(old.weight ?? 0, "half-up") + toInt(old.weight, "down")', 'may be null'];
        yield 'a dot on something nullable' => ['set new.label = old.brand.x', 'read it with ?.'];
        yield 'a field that wasn\'t there' => ['set new.label = old.nope', 'had no field "nope"'];
        yield 'a field that won\'t be there' => ['set new.nope = 1', 'has no field "nope" after the change'];
        yield 'text plus a number' => ['set new.code = old.priceCurrency + 1', 'must be a number'];
        yield 'a decimal into an int' => ['set new.code = old.priceAmount', 'Convert it'];
        yield 'a text into a number' => ['set new.code = "1"', 'it\'s text'];
        yield 'default inside an expression' => ['set new.label = default ~ "x"', 'default only fills a field'];
        yield 'a value set twice' => ["set new.price.amount = 1\nset new.price = { amount: 2, currency: EUR }", 'set twice'];
        yield 'a record with no type' => ['let x = { a: 1 }', 'needs a type'];
        yield 'an unknown name' => ['set new.label = nobody', 'Unknown name "nobody"'];
        yield 'an unknown case' => ['set new.state = archived', 'has no case "archived"'];
        yield 'a match leaving cases out' => ['set new.code = match old.state { draft => 1 }', 'leaves out live'];
        yield 'a text match without else' => ['set new.code = match old.priceCurrency { "EUR" => 1 }', 'needs an else'];
        yield 'a value matched twice' => ['set new.code = match old.priceCurrency { "a", "a" => 1 else => 2 }', 'twice'];
        yield 'branches that don\'t meet' => ['set new.label = if old.stock > 0 then "a" else 1', 'don\'t meet'];
        yield 'an owned object of another type' => ['set new.supplier = new shop:money { amount: 1 }', 'is no shop:party'];
        yield 'an unneeded ??' => ['set new.label = old.priceCurrency ?? ""', 'never null'];
        yield 'a never-null value compared with null' => ['set new.code = if old.stock == null then 1 else 2', 'never null'];
        yield 'an unknown built-in' => ['set new.label = shout(old.name)', 'no built-in shout'];
        yield 'a rounding mode not written as is' => ['set new.code = toInt(old.priceAmount, old.priceCurrency)', 'rounding mode'];
        yield 'a let used before it\'s set' => ["set new.label = later\nlet later = \"x\"", 'Unknown name "later"'];
        yield 'a let named twice' => ["let a = 1\nlet a = 2", 'already a let'];
        yield 'isDefault on something computed' => ['set new.code = if isDefault(old.stock + 1) then 1 else 2', 'isDefault takes a field read from old'];
        yield 'a condition that isn\'t a bool' => ['if old.stock { set new.code = 1 }', 'must be a bool'];
        yield 'toEnum with no enum expected' => ['let s = toEnum(old.priceCurrency)', 'needs to know its enum'];
    }

    #[DataProvider('mistakes')]
    public function testMistakesAreReported(string $statements, string $problem): void {
        $problems = self::problems($statements);

        self::assertNotEmpty(array_filter($problems, static fn (string $found): bool => str_contains($found, $problem)), implode("\n", $problems));
    }

    public function testEveryProblemIsReportedOnce(): void {
        $problems = self::problems(<<<'TEXT'
            set new.code = old.nope + 1
            set new.label = old.supplierName
            TEXT);

        self::assertCount(2, $problems, implode("\n", $problems));
    }

    public function testAnEmbedWhoseShapeChangesCantBeCopiedWhole(): void {
        $checker = new Checker(
            new ArrayShapes(['t:a' => ['size' => Parser::type('embed t:size')], 't:size' => ['w' => Type::int()]]),
            new ArrayShapes(['t:a' => ['size' => Parser::type('embed t:size')], 't:size' => ['w' => Type::decimal()]]),
            Builtins::standard(),
        );

        try {
            $checker->check(Parser::step('data in t:a { set new.size = old.size }'));
            self::fail('The copy checked.');
        } catch (InvalidStep $invalid) {
            self::assertStringContainsString('set its fields one by one', $invalid->problems[0]);
        }

        $checker->check(Parser::step('data in t:a { set new.size = { w: old.size.w } }'));
    }

    public function testAStepRunsInATypeThatExistsBeforeAndAfter(): void {
        $this->expectException(InvalidStep::class);
        $this->expectExceptionMessage('shop:money');

        self::checker()->check(Parser::step('data in shop:money { }'));
    }

    public function testProblemsShowTheTextTheyAreAbout(): void {
        $problems = self::problems('set new.code = old.priceCurrency + 1');

        self::assertStringContainsString('In: ' . Printer::expression(Parser::expression('old.priceCurrency + 1')), $problems[0]);
    }
}
