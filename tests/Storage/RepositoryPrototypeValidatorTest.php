<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Repository;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Storage\ValidationException;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\Booking;

final class RepositoryPrototypeValidatorTest extends TestCase
{
    private Repository $repository;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $fields = PrototypeShape::ofClass(Booking::class);
        (new SchemaSynchronizer($connection))->sync(SchemaBuilder::tableFor('bookings', $fields));

        $this->repository = (new EntityManager($connection, [Booking::class => 'bookings']))->repository(Booking::class);
    }

    public function testAValidCandidateStatePassesOnInsert(): void
    {
        $id = $this->repository->insert(new Booking(1, 5));

        self::assertSame(1, $this->repository->find($id)->startDay);
    }

    public function testAnInvalidCandidateStateIsRejectedOnInsert(): void
    {
        $this->expectException(ValidationException::class);

        $this->repository->insert(new Booking(5, 1));
    }

    public function testUpdateIsCheckedAgainstTheFullyMergedCandidateStateNotJustTheChangedField(): void
    {
        $id = $this->repository->insert(new Booking(1, 5));

        $this->expectException(ValidationException::class);

        // only endDay is being changed, but merged with the unchanged startDay=1 this is still invalid
        $this->repository->update($id, ['endDay' => 0]);
    }

    public function testUpdateSucceedsWhenTheMergedCandidateStateIsValid(): void
    {
        $id = $this->repository->insert(new Booking(1, 5));

        $updated = $this->repository->update($id, ['endDay' => 10]);

        self::assertSame(10, $updated->endDay);
    }
}
