<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Identity;

use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;

final class IdentityMapTest extends TestCase
{
    public function testARememberedObjectAndIdFindEachOther(): void {
        $map = new IdentityMap();
        $entity = new stdClass();

        $map->remember($entity, 'a');

        self::assertSame('a', $map->idOf($entity));
        self::assertSame($entity, $map->objectOf('a'));
    }

    public function testAnObjectNeverRememberedHasNoId(): void {
        self::assertNull((new IdentityMap())->idOf(new stdClass()));
    }

    public function testRememberingTheSamePairAgainIsFine(): void {
        $map = new IdentityMap();
        $entity = new stdClass();
        $map->remember($entity, 'a');

        $map->remember($entity, 'a');

        self::assertSame('a', $map->idOf($entity));
    }

    public function testAnObjectCannotTakeASecondId(): void {
        $map = new IdentityMap();
        $entity = new stdClass();
        $map->remember($entity, 'a');

        $this->expectException(LogicException::class);

        $map->remember($entity, 'b');
    }

    public function testTwoLiveObjectsCannotShareAnId(): void {
        $map = new IdentityMap();
        $first = new stdClass();
        $map->remember($first, 'a');

        $this->expectException(LogicException::class);

        $map->remember(new stdClass(), 'a');
    }

    public function testRememberingNeverKeepsAnObjectAlive(): void {
        $map = new IdentityMap();
        $entity = new stdClass();
        $map->remember($entity, 'a');

        unset($entity);

        self::assertNull($map->objectOf('a'));
        $map->remember(new stdClass(), 'a');
    }

    public function testForgottenIdsAndObjectsAreUnknownAgain(): void {
        $map = new IdentityMap();
        $entity = new stdClass();
        $map->remember($entity, 'a');

        $map->forget('a');

        self::assertNull($map->idOf($entity));
        self::assertNull($map->objectOf('a'));
    }
}
