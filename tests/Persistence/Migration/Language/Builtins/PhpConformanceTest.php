<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Language\Builtins;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Builtins;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\PhpCompiler;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\StepFailed;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Parser;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\ArrayShapes;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Checker;

/** The PHP backend gives what every built-in case expects. */
final class PhpConformanceTest extends TestCase
{
    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $values
     */
    #[DataProviderExternal(BuiltinCases::class, 'all')]
    public function testTheBuiltinGivesWhatItMeans(string $expression, array $fields, array $values, string $outType, mixed $expected): void {
        $old = array_map(Parser::type(...), $fields);
        $enums = ['t:currency' => ['EUR' => 'eur', 'USD' => 'usd']];
        $checker = new Checker(
            new ArrayShapes(['t:row' => $old], [], $enums),
            new ArrayShapes(['t:row' => [...$old, 'out' => Parser::type($outType)]], [], $enums),
            Builtins::standard(),
        );
        $step = PhpCompiler::compile($checker->check(Parser::step("data in t:row { set new.out = $expression }")), Builtins::standard());

        if (is_string($expected) && str_starts_with($expected, BuiltinCases::FAILS)) {
            $this->expectException(StepFailed::class);
            $this->expectExceptionMessage(substr($expected, strlen(BuiltinCases::FAILS)));
        }

        self::assertSame(['out' => $expected], $step->run($values));
    }
}
