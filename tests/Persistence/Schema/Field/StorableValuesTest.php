<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use LengthException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;

final class StorableValuesTestCode implements StorableValue
{
    public function __construct(
        private readonly string $code,
    ) {
    }

    public function toStorage(): string {
        return $this->code;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

final class StorableValuesTestText implements StorableValue
{
    public function __construct(
        private readonly string $text,
    ) {
    }

    public function toStorage(): string {
        return $this->text;
    }

    public static function indexable(): bool {
        return false;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

final class StorableValuesTest extends TestCase
{
    public function testAnIndexableValueUpToTheLimitIsStoredAsIs(): void {
        $code = str_repeat('a', StorableValues::MAX_INDEXABLE_LENGTH);

        self::assertSame($code, StorableValues::toStorage(new StorableValuesTestCode($code)));
    }

    public function testTheLimitCountsCharactersNotBytes(): void {
        // 255 characters, 510 bytes: fits VARCHAR(255) in utf8mb4.
        $code = str_repeat('è', StorableValues::MAX_INDEXABLE_LENGTH);

        self::assertSame($code, StorableValues::toStorage(new StorableValuesTestCode($code)));
    }

    public function testAnIndexableValuePastTheLimitThrows(): void {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('stores as 256 characters');

        StorableValues::toStorage(new StorableValuesTestCode(str_repeat('a', 256)));
    }

    public function testAValueNotIndexableHasNoLimit(): void {
        $text = str_repeat('a', 10_000);

        self::assertSame($text, StorableValues::toStorage(new StorableValuesTestText($text)));
    }
}
