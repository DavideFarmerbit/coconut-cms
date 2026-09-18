<?php

namespace XyloIsCoding\CoconutCms\Tests\Core;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Core\Kernel;

final class KernelTest extends TestCase
{
    public function testBindAndGetDelegateToTheUnderlyingContainer(): void
    {
        $kernel = new Kernel();
        $id = Identifier::of('app', 'thing');

        $kernel->bind($id, fn () => new \stdClass());

        self::assertInstanceOf(\stdClass::class, $kernel->get($id));
    }
}
