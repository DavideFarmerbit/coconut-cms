<?php

namespace XyloIsCoding\CoconutCms\Tests\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Container;
use XyloIsCoding\CoconutCms\Core\Identifier;

final class ContainerTest extends TestCase
{
    public function testGetReturnsTheBoundFactorysResult(): void
    {
        $container = new Container();
        $id = Identifier::of('app', 'thing');

        $container->bind($id, fn () => new \stdClass());

        self::assertInstanceOf(\stdClass::class, $container->get($id));
    }

    public function testGetReusesTheSameInstanceAcrossCalls(): void
    {
        $container = new Container();
        $id = Identifier::of('app', 'thing');
        $calls = 0;

        $container->bind($id, function () use (&$calls) {
            $calls++;
            return new \stdClass();
        });

        $first = $container->get($id);
        $second = $container->get($id);

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function testBindingTheSameIdentifierTwiceThrows(): void
    {
        $container = new Container();
        $id = Identifier::of('app', 'thing');

        $container->bind($id, fn () => new \stdClass());

        $this->expectException(RuntimeException::class);
        $container->bind($id, fn () => new \stdClass());
    }

    public function testGettingAnUnboundIdentifierThrows(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->get(Identifier::of('app', 'never-bound'));
    }
}
