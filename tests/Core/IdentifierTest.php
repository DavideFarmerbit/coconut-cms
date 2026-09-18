<?php

namespace XyloIsCoding\CoconutCms\Tests\Core;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;

final class IdentifierTest extends TestCase
{
    public function testOfCreatesIdentifierWithNamespaceAndKey(): void
    {
        $id = Identifier::of('app', 'assets.frontend');

        self::assertSame('app', $id->namespace);
        self::assertSame('assets.frontend', $id->key);
    }

    public function testToStringCombinesNamespaceAndKey(): void
    {
        $id = Identifier::of('app', 'assets.frontend');

        self::assertSame('app:assets.frontend', (string) $id);
    }
}
