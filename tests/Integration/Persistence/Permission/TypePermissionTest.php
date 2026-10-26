<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Query\Is;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** Type permissions: who may read, create, update and delete a type's entities. */
final class TypePermissionTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new PermissionModule()]);
        $this->persistence->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** A new session, as a request of an actor holding $roles gets. */
    private function as(string ...$roles): PersistenceSession {
        return $this->persistence->as(new PermActor(array_values($roles)));
    }

    /** Asserts $work throws PermissionDenied for $action on a $identifier. */
    private static function assertDenied(TypeAction $action, string $identifier, callable $work): void {
        try {
            $work();
            self::fail(sprintf('Expected the %s to be refused.', $action->value));
        } catch (PermissionDenied $denied) {
            self::assertSame($action, $denied->action);
            self::assertSame($identifier, $denied->identifier);
        }
    }

    private function savedDocument(string $title = 'Report'): string {
        $document = new PermDocument();
        $document->title = $title;

        return $this->as('creator')->save($document);
    }

    /** @return array{string, string, string} The ids of a plain maker, a secret one and a partner one. */
    private function savedMakers(): array {
        $session = $this->as('admin', 'partner');
        $ids = [];
        foreach ([new PermMaker(), new PermSecretMaker(), new PermPartnerMaker()] as $index => $maker) {
            $maker->name = ['Open Co', 'Hidden Co', 'Partner Co'][$index];
            $ids[] = $session->save($maker);
        }

        return $ids;
    }

    public function testCreatingNeedsTheCreateRole(): void {
        self::assertDenied(TypeAction::Create, 'perm:document', fn () => $this->as('editor')->save(new PermDocument()));

        self::assertNotNull($this->as('creator')->save(new PermDocument()));
    }

    public function testChangingAStoredEntityNeedsTheUpdateRole(): void {
        $id = $this->savedDocument();
        $session = $this->as('reader');
        $document = $session->load($id);

        $session->save($document);
        $document->title = 'Changed';
        self::assertDenied(TypeAction::Update, 'perm:document', fn () => $session->save($document));
        self::assertSame('Report', $this->as('reader')->load($id)->title);

        $editor = $this->as('reader', 'editor');
        $document = $editor->load($id);
        $document->title = 'Changed';
        $editor->save($document);
        self::assertSame('Changed', $this->as('reader')->load($id)->title);
    }

    public function testDeletingNeedsTheDeleteRole(): void {
        $id = $this->savedDocument();

        $session = $this->as('reader');
        self::assertDenied(TypeAction::Delete, 'perm:document', fn () => $session->delete($session->load($id)));
        self::assertNotNull($this->as('reader')->load($id));

        $remover = $this->as('reader', 'remover');
        $remover->delete($remover->load($id));
        self::assertNull($this->as('reader')->load($id));
    }

    public function testReadingNeedsTheReadRole(): void {
        $id = $this->savedDocument();

        self::assertDenied(TypeAction::Read, 'perm:document', fn () => $this->as()->load($id));
        self::assertDenied(TypeAction::Read, 'perm:document', fn () => $this->as()->query(PermDocument::class)->count());
        self::assertSame(1, $this->as('reader')->query(PermDocument::class)->count());
    }

    public function testAnOwnedObjectFollowsItsRoot(): void {
        $document = new PermDocument();
        $document->engine = new PermEngine();
        $id = $this->as('creator')->save($document);

        $session = $this->as('reader', 'editor');
        $loaded = $session->load($id);
        $loaded->engine->horsepower = 250;
        $session->save($loaded);
        self::assertSame(250, $this->as('reader')->load($id)->engine->horsepower);

        self::assertDenied(TypeAction::Create, 'perm:engine', fn () => $this->as('creator')->save(new PermEngine()));
        self::assertNotNull($this->as('engine-room')->save(new PermEngine()));
    }

    public function testASubtypeOnlyAddsRestrictions(): void {
        [, $secretId, $partnerId] = $this->savedMakers();

        self::assertDenied(TypeAction::Read, 'perm:secret_maker', fn () => $this->as()->load($secretId));
        self::assertDenied(TypeAction::Read, 'perm:partner_maker', fn () => $this->as('partner')->load($partnerId));
        self::assertDenied(TypeAction::Read, 'perm:partner_maker', fn () => $this->as('admin')->load($partnerId));
        self::assertSame('Partner Co', $this->as('admin', 'partner')->load($partnerId)->name);
    }

    public function testAQueryLeavesOutTheSubtypesItsActorMayNotRead(): void {
        $this->savedMakers();

        $names = static fn (PersistenceSession $session): array => array_map(
            static fn (PermMaker $maker): string => $maker->name,
            $session->query(PermMaker::class)->orderBy('name')->limit(10)->page()->items,
        );

        self::assertSame(['Open Co'], $names($this->as()));
        self::assertSame(1, $this->as()->query(PermMaker::class)->count());
        self::assertSame(['Hidden Co', 'Open Co'], $names($this->as('admin')));
        self::assertSame(['Hidden Co', 'Open Co', 'Partner Co'], $names($this->as('admin', 'partner')));
    }

    public function testAReferenceToWhatItsActorMayNotReadIsAGhostThatThrows(): void {
        [, $secretId] = $this->savedMakers();
        $admin = $this->as('admin');
        $car = new PermCar();
        $car->name = 'Family car';
        $car->maker = $admin->load($secretId);
        $id = $admin->save($car);

        $session = $this->as();
        $loaded = $session->load($id);
        self::assertSame($secretId, $loaded->maker->id, 'Its id is still there.');
        self::assertDenied(TypeAction::Read, 'perm:secret_maker', static fn () => $loaded->maker->name);
        self::assertDenied(TypeAction::Read, 'perm:secret_maker', static fn () => $session->load($secretId));

        $loaded->name = 'Renamed';
        $session->save($loaded);
        self::assertSame('Hidden Co', $this->as('admin')->load($id)->maker->name);
    }

    public function testAPathPastAReferenceHidesWhatItsActorMayNotRead(): void {
        [, $secretId] = $this->savedMakers();
        $admin = $this->as('admin', 'reader', 'creator');
        $car = new PermCar();
        $car->maker = $admin->load($secretId);
        $document = new PermDocument();
        $document->title = 'Manual';
        $admin->save($document);
        $car->document = $document;
        $admin->save($car);

        // A target of a subtype it may not read joins as missing.
        self::assertSame(1, $this->as('admin')->query(PermCar::class)->where('maker.name', Is::equalTo('Hidden Co'))->count());
        self::assertSame(0, $this->as()->query(PermCar::class)->where('maker.name', Is::equalTo('Hidden Co'))->count());
        self::assertSame(1, $this->as()->query(PermCar::class)->where('maker.name', Is::null())->count());
        self::assertSame(1, $this->as()->query(PermCar::class)->where('maker', Is::notNull())->count());

        // A path past a Reference to a type it may not read is refused.
        self::assertSame(1, $this->as('reader')->query(PermCar::class)->where('document.title', Is::equalTo('Manual'))->count());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('may not read a perm:document');
        $this->as()->query(PermCar::class)->where('document.title', Is::equalTo('Manual'))->count();
    }
}
