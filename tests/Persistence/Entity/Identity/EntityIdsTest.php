<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Identity;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;

final class EntityIdsTest extends TestCase
{
    public function testAGeneratedIdIsAVersion7Uuid(): void {
        $id = EntityIds::generate();

        self::assertTrue(EntityIds::isValid($id));
        self::assertSame('7', $id[14]);
        self::assertContains($id[19], ['8', '9', 'a', 'b']);
    }

    public function testGeneratedIdsAreUnique(): void {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = EntityIds::generate();
        }

        self::assertCount(1000, array_unique($ids));
    }

    public function testAnIdGeneratedLaterSortsAfter(): void {
        $earlier = EntityIds::generate();
        usleep(2000);
        $later = EntityIds::generate();

        self::assertLessThan(0, strcmp($earlier, $later));
    }

    public function testTheNilIdIsValid(): void {
        self::assertTrue(EntityIds::isValid(EntityIds::NIL));
    }

    public function testSomethingNotShapedLikeAUuidIsInvalid(): void {
        self::assertFalse(EntityIds::isValid('not-a-uuid'));
        self::assertFalse(EntityIds::isValid(strtoupper(EntityIds::generate())));
    }
}
