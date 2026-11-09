<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Language\Php;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Builtins;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\CompiledStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\Created;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\FollowsDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\PhpCompiler;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\StepFailed;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Parser;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DataStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\TreeCodec;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\ArrayShapes;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Checker;

/** Whole steps run on one product: flat fields become a money Embed and an owned supplier. */
final class CompiledStepTest extends TestCase
{
    private const CHANGE = <<<'TEXT'
        data in shop:product {
          set new.price = {
            amount: old.priceAmount,
            currency: match upper(trim(old.priceCurrency)) {
              "EUR", "€" => EUR
              "USD", "$" => USD
              else => fail("unknown currency: " ~ old.priceCurrency)
            }
          }
          let name = trim(old.supplierName ?? "")
          set new.supplier = if name == "" then null else new shop:supplier {
            name: name,
            email: if old.supplierEmail == null then null else lower(old.supplierEmail)
          }
          if isDefault(old.stock) {
            set new.stock = default
          } else if old.stock < 0 {
            fail("negative stock")
          } else {
            set new.stock = old.stock
          }
        }
        TEXT;

    private static function checker(): Checker {
        $t = Parser::type(...);
        $enums = ['shop:currency' => ['EUR' => 'eur', 'USD' => 'usd']];

        return new Checker(
            new ArrayShapes(['shop:product' => [
                'priceAmount' => $t('decimal'),
                'priceCurrency' => $t('text'),
                'supplierName' => $t('text?'),
                'supplierEmail' => $t('text?'),
                'stock' => $t('int'),
            ]], [], $enums),
            new ArrayShapes([
                'shop:product' => ['price' => $t('embed shop:money'), 'supplier' => $t('owns shop:supplier?'), 'stock' => $t('decimal')],
                'shop:money' => ['amount' => $t('decimal'), 'currency' => $t('enum shop:currency')],
                'shop:supplier' => ['name' => $t('string'), 'email' => $t('text?')],
            ], [], $enums),
            Builtins::standard(),
        );
    }

    private static function compiled(string|DataStep $step): CompiledStep {
        return PhpCompiler::compile(self::checker()->check(is_string($step) ? Parser::step($step) : $step), Builtins::standard());
    }

    public function testAProductMovesIntoItsNewShape(): void {
        $set = self::compiled(self::CHANGE)->run([
            'priceAmount' => '12.500000',
            'priceCurrency' => ' € ',
            'supplierName' => ' Acme ',
            'supplierEmail' => 'Sales@Acme.com',
            'stock' => 3,
        ]);

        self::assertEquals([
            'price' => ['amount' => '12.500000', 'currency' => 'eur'],
            'supplier' => new Created('shop:supplier', ['name' => 'Acme', 'email' => 'sales@acme.com']),
            'stock' => '3.000000',
        ], $set);
    }

    public function testAProductWithNoSupplierGetsNone(): void {
        $set = self::compiled(self::CHANGE)->run(['priceAmount' => '1.000000', 'priceCurrency' => 'usd', 'supplierName' => '  ', 'supplierEmail' => null, 'stock' => 0]);

        self::assertNull($set['supplier']);
        self::assertSame(['amount' => '1.000000', 'currency' => 'usd'], $set['price']);
    }

    public function testAValueFollowingItsDefaultKeepsFollowingIt(): void {
        $set = self::compiled(self::CHANGE)->run(
            ['priceAmount' => '1.000000', 'priceCurrency' => 'EUR', 'supplierName' => null, 'supplierEmail' => null, 'stock' => 0],
            ['stock' => true],
        );

        self::assertSame(FollowsDefault::Default, $set['stock']);
    }

    public function testAFailureNamesWhatFailed(): void {
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('unknown currency: GBP');

        self::compiled(self::CHANGE)->run(['priceAmount' => '1.000000', 'priceCurrency' => 'GBP', 'supplierName' => null, 'supplierEmail' => null, 'stock' => 1]);
    }

    public function testAFailStatementFails(): void {
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('negative stock');

        self::compiled(self::CHANGE)->run(['priceAmount' => '1.000000', 'priceCurrency' => 'EUR', 'supplierName' => null, 'supplierEmail' => null, 'stock' => -1]);
    }

    public function testAStoredTreeCompilesLikeItsText(): void {
        $fromText = self::compiled(self::CHANGE);
        $fromJson = self::compiled(TreeCodec::decodeStep(TreeCodec::encodeStep(Parser::step(self::CHANGE))));

        self::assertSame($fromText->source, $fromJson->source);
    }

    public function testNamesFromAStoredTreeNeverReachTheSourceUnescaped(): void {
        $hostile = "x'); echo 'pwned'; ('";
        $checker = new Checker(
            new ArrayShapes(['t:a' => [$hostile => Parser::type('text')]]),
            new ArrayShapes(['t:a' => [$hostile => Parser::type('text')]]),
            Builtins::standard(),
        );
        $json = json_encode(['data' => 't:a', 'steps' => [
            ['let' => $hostile, 'be' => ['get' => $hostile, 'of' => ['old' => true]]],
            ['set' => [$hostile], 'to' => ['call' => 'concat', 'args' => [['var' => $hostile], "\\'; echo 'pwned';"]]],
        ]]);

        $step = PhpCompiler::compile($checker->check(TreeCodec::decodeStep($json)), Builtins::standard());

        $this->expectOutputString('');
        self::assertSame([$hostile => "a\\'; echo 'pwned';"], $step->run([$hostile => 'a']));
    }

    public function testTheSameStepCompilesOnce(): void {
        self::assertSame(self::compiled(self::CHANGE), self::compiled(self::CHANGE));
    }
}
