<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Permission;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Permission\FieldPermissionFilter;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\RowMapper;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission\Employee;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission\Salary;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\SimpleActor;

final class FieldPermissionFilterTest extends TestCase
{
    private function values(): array
    {
        $fields = PrototypeShape::ofClass(Employee::class);
        $employee = new Employee('Alice', '123-45-6789', new Salary(90000, 'confidential note'));

        return [$fields, RowMapper::propertiesOf($employee, $fields)];
    }

    public function testAnActorWithoutReadPermissionNeverSeesTheFieldKeyAtAll(): void
    {
        [$fields, $values] = $this->values();

        $filtered = FieldPermissionFilter::forRead($fields, $values, new SimpleActor([]));

        self::assertArrayNotHasKey('ssn', $filtered);
        self::assertSame('Alice', $filtered['name']);
    }

    public function testAnActorWithReadPermissionSeesTheField(): void
    {
        [$fields, $values] = $this->values();

        $filtered = FieldPermissionFilter::forRead($fields, $values, new SimpleActor(['hr']));

        self::assertSame('123-45-6789', $filtered['ssn']);
    }

    public function testFilteringRecursesIntoAnEmbeddedValueObjectsOwnRestrictedField(): void
    {
        [$fields, $values] = $this->values();

        $forOutsider = FieldPermissionFilter::forRead($fields, $values, new SimpleActor([]));
        $forHr = FieldPermissionFilter::forRead($fields, $values, new SimpleActor(['hr']));

        self::assertArrayNotHasKey('notes', $forOutsider['salary']);
        self::assertSame(90000, $forOutsider['salary']['amount']);
        self::assertSame('confidential note', $forHr['salary']['notes']);
    }
}
