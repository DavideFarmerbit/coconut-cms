<?php

namespace XyloIsCoding\CoconutCms\Tests\Api;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Api\Output\ResultWriter;
use XyloIsCoding\CoconutCms\Api\Output\TextTable;

/** ResultWriter: results as the JSON every transport sends. */
final class ResultWriterTest extends TestCase
{
    protected function setUp(): void {
        // The fixture file holds several classes: load it by its own.
        class_exists(ApiFixtures::class);
    }

    private static function json(mixed $value): string {
        return json_encode((new ResultWriter())->write($value), JSON_THROW_ON_ERROR);
    }

    public function testAResultIsWrittenByItsPublicProperties(): void {
        $result = new FixtureResult(['a' => 1, '' => 2], ['x', 'y'], [], FixtureColor::Blue, new FixtureResult([], [], [], FixtureColor::Red));

        self::assertSame(
            '{"counts":{"a":1,"":2},"names":["x","y"],"empty":{},"color":"blue","child":{"counts":{},"names":[],"empty":{},"color":"red","child":null}}',
            self::json($result),
        );
    }

    public function testAnArrayWithKeysThatArentAListsIsAnObject(): void {
        self::assertSame('{"1":"a","3":"b"}', self::json([1 => 'a', 3 => 'b']));
        self::assertSame('["a","b"]', self::json(['a', 'b']));
    }

    public function testATableRendersPaddedColumns(): void {
        $table = new TextTable(['ID', 'NAME'], [['1', 'Alpha'], ['22', 'B']], 'More');

        self::assertSame("ID  NAME\n--  -----\n1   Alpha\n22  B\n\nMore\n", str_replace(PHP_EOL, "\n", $table->render()));
    }
}
