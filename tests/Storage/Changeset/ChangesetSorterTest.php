<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetSorter;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\TempId;

final class ChangesetSorterTest extends TestCase
{
    public function testAChangeReferencingATempIdComesAfterTheCreateThatOwnsIt(): void
    {
        $category = new TempId('cat');
        $product = new TempId('prod');

        $sorted = ChangesetSorter::sort([
            EntityChange::create($product, 'Product', ['category' => $category]),
            EntityChange::create($category, 'Category', ['name' => 'Widgets']),
        ]);

        self::assertSame(['Category', 'Product'], array_map(static fn (EntityChange $c): string => $c->prototypeClass, $sorted));
    }

    public function testATempIdNestedInsideACollectionIsStillFound(): void
    {
        $tag = new TempId('tag');

        $sorted = ChangesetSorter::sort([
            EntityChange::create(new TempId('prod'), 'Product', ['tags' => [$tag, 'existing-id']]),
            EntityChange::create($tag, 'Tag', ['name' => 'sale']),
        ]);

        self::assertSame(['Tag', 'Product'], array_map(static fn (EntityChange $c): string => $c->prototypeClass, $sorted));
    }

    public function testIndependentChangesKeepTheirOriginalRelativeOrder(): void
    {
        $sorted = ChangesetSorter::sort([
            EntityChange::delete('1', 'Product'),
            EntityChange::delete('2', 'Category'),
        ]);

        self::assertSame(['Product', 'Category'], array_map(static fn (EntityChange $c): string => $c->prototypeClass, $sorted));
    }

    public function testAnUpdateReferencingAFreshTempIdIsOrderedAfterItsCreate(): void
    {
        $category = new TempId('cat');

        $sorted = ChangesetSorter::sort([
            EntityChange::update('42', 'Product', ['category' => $category]),
            EntityChange::create($category, 'Category', ['name' => 'Widgets']),
        ]);

        self::assertSame(['Category', 'Product'], array_map(static fn (EntityChange $c): string => $c->prototypeClass, $sorted));
    }

    public function testTwoCreatesReferencingEachOthersTempIdIsRejectedAsACycle(): void
    {
        $a = new TempId('a');
        $b = new TempId('b');

        $this->expectException(LogicException::class);

        ChangesetSorter::sort([
            EntityChange::create($a, 'A', ['other' => $b]),
            EntityChange::create($b, 'B', ['other' => $a]),
        ]);
    }

    public function testReferencingAnUnknownTempIdIsRejected(): void
    {
        $this->expectException(LogicException::class);

        ChangesetSorter::sort([
            EntityChange::create(new TempId('a'), 'A', ['other' => new TempId('does-not-exist')]),
        ]);
    }
}
