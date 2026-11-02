<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\EntrySample;

/** What a preview shows of one entry: only what changes in it, flat, old names before and new ones after. */
final class EntrySampleTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     * @return array<string, ValueMark> Every path touched, but those in $following.
     */
    private static function marks(array $values, string ...$following): array {
        $marks = array_map(static fn (): ValueMark => ValueMark::Touched, $values);
        foreach ($following as $path) {
            $marks[$path] = ValueMark::Untouched;
        }

        return $marks;
    }

    public function testARenameShowsTheOldPathBeforeAndTheNewOneAfter(): void {
        $before = ['name' => 'Sea', 'cabins' => 3];
        $after = ['name' => 'Sea', 'rooms' => 3];

        $sample = EntrySample::of('01a1', 'dy:yacht', $before, self::marks($before), $after, self::marks($after));

        self::assertSame(['cabins' => 3], $sample->before);
        self::assertSame(['rooms' => 3], $sample->after);
    }

    public function testAValueFollowingADefaultIsNotTheEntrysOwn(): void {
        $before = ['color' => 'red', 'size' => 2];
        $after = ['color' => 'crimson', 'size' => 5];

        $sample = EntrySample::of('01a1', 'dy:buoy', $before, self::marks($before, 'size'), $after, self::marks($after, 'size'));

        self::assertSame(['color' => 'red'], $sample->before);
        self::assertSame(['color' => 'crimson'], $sample->after, 'The size follows the default, old and new.');
    }

    public function testAValueBecomingAListShowsItsCountAndItems(): void {
        $before = ['color' => 'red'];
        $after = ['color' => 1, 'color.0' => 'red'];

        $sample = EntrySample::of('01a1', 'dy:buoy', $before, self::marks($before), $after, self::marks($after));

        self::assertSame([['color' => 'red'], ['color' => 1, 'color.0' => 'red']], [$sample->before, $sample->after]);
    }

    public function testAnEntryWhereNothingOfItsOwnChangesIsNoSample(): void {
        $values = ['name' => 'Sea'];

        self::assertNull(EntrySample::of('01a1', 'dy:yacht', $values, self::marks($values), $values, self::marks($values)));
    }

    public function testTypesCountWhenComparing(): void {
        $before = ['cabins' => 4];
        $after = ['cabins' => '4.000000'];

        $sample = EntrySample::of('01a1', 'dy:yacht', $before, self::marks($before), $after, self::marks($after));

        self::assertSame([['cabins' => 4], ['cabins' => '4.000000']], [$sample->before, $sample->after]);
    }
}
