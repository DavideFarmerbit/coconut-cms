<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Language\Syntax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Parser;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Printer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\SyntaxError;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\InvalidTree;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Literal;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LiteralKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Member;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Name;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Old;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\TreeCodec;

final class SyntaxTest extends TestCase
{
    private const STEP = <<<'TEXT'
        data in shop:product {
          // Prices move into an Embed.
          set new.price = {
            amount: old.priceAmount * 100,
            currency: match upper(trim(old.priceCurrency)) {
              "EUR", "€" => EUR
              "USD", "$" => USD
              else => fail("unknown currency: " ~ old.priceCurrency)
            }
          }
          let name = trim(old.supplierName ?? "")
          set new.supplier = if name == "" then null else new coconut-cms:supplier.v2 { name: name, `odd name`: -3 }
          set new.size = old.items.map(i => i.size as decimal).filter(s => s > 2).sum()
          if not old.a == 1 and (old.b or old.c) {
            fail("line\n\"quoted\" \u{1F600}")
          } else if old.d < @2024-01-31 {
            set new.at = @2024-01-31T12:00:00+02:00
          } else {
            set new.at = try(toDateTime(old.text), default)
          }
        }
        TEXT;

    public function testPrintingAParsedStepParsesBackToTheSameTree(): void {
        $step = Parser::step(self::STEP);

        self::assertEquals($step, Parser::step(Printer::step($step)));
    }

    public function testPrintingIsCanonical(): void {
        $printed = Printer::step(Parser::step(self::STEP));

        self::assertSame($printed, Printer::step(Parser::step($printed)));
        self::assertStringContainsString('set new.at = @2024-01-31T10:00:00Z', $printed);
        self::assertStringContainsString('new coconut-cms:supplier.v2 { name: name, `odd name`: -3 }', $printed);
        self::assertStringContainsString('fail("line\n\"quoted\" 😀")', $printed);
    }

    public function testJsonGivesTheSameTreeBack(): void {
        $step = Parser::step(self::STEP);
        $json = TreeCodec::encodeStep($step);

        self::assertEquals($step, TreeCodec::decodeStep($json));
        self::assertSame($json, TreeCodec::encodeStep(TreeCodec::decodeStep($json)));
    }

    public function testJsonKeysMayComeInAnyOrder(): void {
        $step = TreeCodec::decodeStep('{"steps": [{"to": {"of": {"old": true}, "get": "a"}, "set": ["b"]}], "data": "t:x"}');

        self::assertSame("data in t:x {\n  set new.b = old.a\n}\n", Printer::step($step));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJson(): iterable {
        yield 'unknown key' => ['{"data": "t:x", "steps": [{"set": ["a"], "to": 1, "extra": 2}]}'];
        yield 'unknown node' => ['{"data": "t:x", "steps": [{"set": ["a"], "to": {"eval": "x"}}]}'];
        yield 'two kinds at once' => ['{"data": "t:x", "steps": [{"set": ["a"], "to": {"var": "x", "call": "f", "args": []}}]}'];
        yield 'a bad number' => ['{"data": "t:x", "steps": [{"set": ["a"], "to": {"number": "1e5"}}]}'];
        yield 'an empty path' => ['{"data": "t:x", "steps": [{"set": [], "to": 1}]}'];
        yield 'a match without arms' => ['{"data": "t:x", "steps": [{"set": ["a"], "to": {"match": 1, "arms": []}}]}'];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRefused(string $json): void {
        $this->expectException(InvalidTree::class);

        TreeCodec::decodeStep($json);
    }

    /** @return iterable<string, array{string, string}> */
    public static function precedence(): iterable {
        yield 'multiplication first' => ['a + b * c', 'a + b * c'];
        yield 'parentheses kept where needed' => ['(a + b) * c', '(a + b) * c'];
        yield 'parentheses dropped where not' => ['(a * b) + c', 'a * b + c'];
        yield 'left associative' => ['a - (b - c)', 'a - (b - c)'];
        yield 'concat below arithmetic' => ['a ~ b + c', 'a ~ b + c'];
        yield 'coalesce right associative' => ['(a ?? b) ?? c', '(a ?? b) ?? c'];
        yield 'not around a comparison' => ['not (a == b)', 'not a == b'];
        yield 'and binds tighter than or' => ['a or (b and c)', 'a or b and c'];
        yield 'a conditional inside an operator' => ['1 + (if a then 2 else 3)', '1 + (if a then 2 else 3)'];
        yield 'as binds tighter than operators' => ['a as decimal * 2', 'a as decimal * 2'];
        yield 'negation of a sum' => ['-(a + b)', '-(a + b)'];
        yield 'a method on a call' => ['map(old.items, i => i.size)', 'old.items.map(i => i.size)'];
        yield 'safe member' => ['old.a?.b', 'old.a?.b'];
        yield 'keywords as fields' => ['old.if.match', 'old.if.match'];
        yield 'keywords as names, quoted' => ['`if` + 1', '`if` + 1'];
    }

    #[DataProvider('precedence')]
    public function testExpressionsPrintWithTheFewestParentheses(string $text, string $printed): void {
        $expr = Parser::expression($text);

        self::assertSame($printed, Printer::expression($expr));
        self::assertEquals($expr, Parser::expression($printed));
    }

    public function testAMemberChainReadsFromOld(): void {
        self::assertEquals(new Member(new Member(new Old(), 'price'), 'amount', true), Parser::expression('old.price?.amount'));
    }

    public function testABareNameStaysUnresolved(): void {
        self::assertEquals(new Call('eq', [new Name('status'), new Name('Draft')]), Parser::expression('status == Draft'));
    }

    public function testNumbersKeepTheirDigits(): void {
        self::assertEquals(new Literal(LiteralKind::Number, '12.50'), Parser::expression('12.50'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function syntaxErrors(): iterable {
        yield 'an unclosed text' => ["data in t:x {\n  set new.a = \"open\n}", 'Line 2, column 15'];
        yield 'a missing value' => ["data in t:x {\n  set new.a =\n}", 'Line 3, column 1'];
        yield 'a chained comparison' => ['data in t:x { set new.a = 1 < 2 < 3 }', 'don\'t chain'];
        yield 'a keyword as a let' => ['data in t:x { let if = 1 }', 'Expected a name'];
        yield 'not a date' => ['data in t:x { set new.a = @2024-02-30 }', 'is not a date'];
        yield 'a computed match value' => ['data in t:x { set new.a = match x { old.y => 2 else => 3 } }', 'literals and enum cases'];
        yield 'an else not last' => ['data in t:x { set new.a = match x { else => 1  2 => 3 } }', 'else comes last'];
        yield 'a field given twice' => ['data in t:x { set new.a = { b: 1, b: 2 } }', 'given twice'];
        yield 'an unknown escape' => ['data in t:x { set new.a = "\\q" }', 'Unknown escape'];
        yield 'a missing type' => ['data in { }', 'Expected a type name'];
    }

    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrorsSayWhere(string $text, string $message): void {
        try {
            Parser::step($text);
            self::fail('No syntax error.');
        } catch (SyntaxError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
