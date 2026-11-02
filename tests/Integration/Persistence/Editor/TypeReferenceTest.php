<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Editor;

use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\InvalidValue;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;

/**
 * Type references through editor saves: a buoy naming which kind of craft
 * it marks, the native craft or a type extending it. What it names follows
 * a rename, and is cleared when the type is gone or no longer a craft.
 */
final class TypeReferenceTest extends DynamicTestCase
{
    /** Saves $operations, the schema at $revision, as the system. */
    private function save(int $revision, SchemaOperation ...$operations): EditorSaveReport {
        return Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, new StrictPolicy())->saveSchemas(new EditorSave($revision, $operations), new SystemActor());
    }

    /**
     * A red buoy holding $marks, in stored form, once the buoy may name a
     * craft through `marks`, as `$field` says, its default $default.
     * @param array<string, mixed> $marks Keyed by path.
     */
    private function buoyMarking(FieldDescriptor $field, mixed $default, array $marks): string {
        $this->save(1, new AddField('dy:buoy', $field, new DefaultValue($default)));

        return $this->store('dy:buoy', ['color' => 'red', ...$marks]);
    }

    /** What the buoy `$id` marks now. */
    private function marks(string $id): mixed {
        return $this->session()->loadDynamic($id)->get('marks');
    }

    public function testWhatItNamesFollowsARenameThoughItHoldsNothingOfIt(): void {
        $buoy = $this->buoyMarking(FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::Nullable), null, ['marks' => 'dy:yacht']);

        $this->save(2, new RenameType('dy:yacht', 'dy:ketch'));

        self::assertSame('dy:ketch', $this->marks($buoy));
    }

    public function testATypeGoneClearsWhatNamesIt(): void {
        $buoy = $this->buoyMarking(FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::Nullable), null, ['marks' => 'dy:yacht']);

        $this->save(2, new DeleteType('dy:yacht'));

        self::assertNull($this->marks($buoy));
    }

    public function testARequiredOneFollowsItsDefault(): void {
        $buoy = $this->buoyMarking(FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::NotNullable), 'dy:craft', ['marks' => 'dy:yacht']);

        $this->save(2, new DeleteType('dy:yacht'));

        self::assertSame('dy:craft', $this->marks($buoy));
    }

    public function testARequiredItemGoesTheOthersKeepingTheirOrder(): void {
        $items = FieldDescriptor::collection('marks', FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::NotNullable));
        $buoy = $this->buoyMarking($items, [], ['marks' => 2, 'marks.0' => 'dy:yacht', 'marks.1' => 'dy:craft']);
        self::assertSame(['dy:yacht', 'dy:craft'], $this->marks($buoy));

        $this->save(2, new DeleteType('dy:yacht'));

        self::assertSame(['dy:craft'], $this->marks($buoy));
    }

    public function testATypeNoLongerUnderTheBaseIsCleared(): void {
        $buoy = $this->buoyMarking(FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::Nullable), null, ['marks' => 'dy:yacht']);

        $this->save(2, new Reparent('dy:yacht', null));

        self::assertNull($this->marks($buoy));
    }

    public function testItNamesOnlyTheBaseOrATypeExtendingIt(): void {
        $buoy = $this->buoyMarking(FieldDescriptor::typeReference('marks', 'dy:craft', Nullability::Nullable), null, []);
        $session = $this->session();
        $loaded = $session->loadDynamic($buoy);

        $loaded->set('marks', 'dy:yacht');
        $session->save($loaded);
        self::assertSame('dy:yacht', $this->marks($buoy));

        $this->expectException(InvalidValue::class);
        $loaded->set('marks', 'dy:buoy');
    }
}
