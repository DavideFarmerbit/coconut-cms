<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Query\Cursor;

final class CursorTest extends TestCase
{
    public function testDecodeReturnsTheSameSortValueAndId(): void
    {
        $cursor = new Cursor(42, '7');

        $decoded = Cursor::decode($cursor->encode());

        self::assertSame(42, $decoded->sortValue);
        self::assertSame('7', $decoded->id);
    }

    public function testDecodeRoundTripsAStringSortValueToo(): void
    {
        $cursor = new Cursor('Ebook', '3');

        $decoded = Cursor::decode($cursor->encode());

        self::assertSame('Ebook', $decoded->sortValue);
        self::assertSame('3', $decoded->id);
    }

    public function testDecodingGarbageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cursor::decode('not a real cursor');
    }
}
