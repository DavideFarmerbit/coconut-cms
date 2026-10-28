<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Api\Auth\AnonymousActor;
use XyloIsCoding\CoconutCms\Api\Auth\Authenticator;
use XyloIsCoding\CoconutCms\Api\Cli\CommandLine;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Api\Operations\Types\ListTypesInput;
use XyloIsCoding\CoconutCms\Api\Operations\Types\TypeList;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Routing\Router;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermActor;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermCar;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermEngine;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermissionFixtures;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermLabel;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermMaker;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermSecretMaker;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** Whoever the test says calls. */
final class ApiTestAuthenticator implements Authenticator
{
    public Actor $actor;

    public function __construct() {
        $this->actor = new AnonymousActor();
    }

    public function actorFor(Request $request): Actor {
        return $this->actor;
    }
}

/** Fails the way a bug does. */
#[Operation('api-test:explode')]
final readonly class ApiTestExplode
{
    public function run(ListTypesInput $input, OperationContext $context): TypeList {
        throw new RuntimeException('The database password is hunter2.');
    }
}

/** The permission tests' types, and one operation of its own. */
final class ApiTestModule implements Module
{
    public function name(): string {
        return 'api-test';
    }

    public function classes(): array {
        return PermissionFixtures::CLASSES;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [ApiTestExplode::class];
    }
}

/** The API over persistence: its built-in operations, through HTTP and the command line. */
final class ApiTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;
    private ApiTestAuthenticator $authenticator;
    private Api $api;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new ApiTestModule()]);
        $this->persistence->migrate();
        $this->authenticator = new ApiTestAuthenticator();
        $this->api = Api::boot($this->persistence, $this->authenticator);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /**
     * POSTs $body to $operation, as the router would.
     * @return array{int, array<string, mixed>} The status and the decoded body.
     */
    private function post(string $operation, string $body, string ...$roles): array {
        $this->authenticator->actor = new PermActor(array_values($roles));
        $reply = $this->api->handle(Request::of(RequestMethod::POST, '/api/' . $operation, body: $body), $operation);

        return [$reply->status, json_decode($reply->body, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** A car with every field set, and its maker. @return array{string, string} Their ids. */
    private function savedCar(string $name = 'Family car', int $price = 10): array {
        $system = $this->persistence->as(new SystemActor());
        $maker = new PermMaker();
        $maker->name = 'Acme';
        $system->save($maker);

        $car = new PermCar();
        $car->name = $name;
        $car->price = $price;
        $car->internal = 'classified';
        $car->engine = new PermEngine();
        $car->engine->horsepower = 300;
        $label = new PermLabel();
        $label->text = 'red';
        $car->labels = [$label];
        $car->maker = $maker;

        return [$system->save($car), $maker->id];
    }

    /**
     * Runs the command line with $arguments.
     * @param list<string> $arguments
     * @return array{int, string, string} The exit status, what it printed, what it printed as errors.
     */
    private function command(array $arguments): array {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = (new CommandLine($this->api))->run($arguments, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, stream_get_contents($out), stream_get_contents($err)];
    }

    public function testTypesListGivesTheReadableTypesAndWhatTheCallerMayDo(): void {
        [$status, $body] = $this->post('coconut-cms:types.list', '');

        self::assertSame(200, $status);
        $types = array_column($body['result']['types'], null, 'identifier');
        self::assertArrayHasKey('perm:car', $types);
        self::assertArrayNotHasKey('perm:secret_maker', $types);
        self::assertArrayNotHasKey('perm:engine', $types);

        $car = $types['perm:car'];
        self::assertSame(['read' => true, 'create' => true, 'update' => true, 'delete' => true], $car['may']);
        self::assertSame(['read' => true, 'write' => false, 'value' => ['kind' => 'scalar', 'nullable' => false, 'scalarType' => 'int', 'enumValues' => null, 'target' => null, 'item' => null]], $car['fields']['price']);
        self::assertFalse($car['fields']['internal']['read']);
        self::assertSame('owning_reference', $car['fields']['engine']['value']['kind']);
        self::assertSame('perm:engine', $car['fields']['engine']['value']['target']);
        self::assertSame(['kind' => 'embed', 'nullable' => false, 'scalarType' => null, 'enumValues' => null, 'target' => 'perm:label', 'item' => null], $car['fields']['labels']['value']['item']);

        [, $admin] = $this->post('coconut-cms:types.list', '{}', 'admin', 'engine-room');
        self::assertContains('perm:secret_maker', array_column($admin['result']['types'], 'identifier'));
    }

    public function testTypesGetRefusesAMissingOrUnreadableType(): void {
        [$status, $body] = $this->post('coconut-cms:types.get', '{"type": "perm:nothing"}');
        self::assertSame(404, $status);
        self::assertSame(['code' => 'not_found', 'message' => 'There is no type "perm:nothing".', 'details' => ['kind' => 'type', 'key' => 'perm:nothing']], $body['error']);

        [$status, $body] = $this->post('coconut-cms:types.get', '{"type": "perm:secret_maker"}');
        self::assertSame(403, $status);
        self::assertSame(['type' => 'perm:secret_maker', 'action' => 'read', 'paths' => []], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:types.get', '{"type": "perm:secret_maker"}', 'admin');
        self::assertSame(200, $status);
        self::assertSame('perm:maker', $body['result']['parent']);
    }

    public function testEntitiesGetGivesStoredValuesTheCallerMayRead(): void {
        [$id, $makerId] = $this->savedCar();

        [$status, $body] = $this->post('coconut-cms:entities.get', json_encode(['id' => $id]));

        self::assertSame(200, $status);
        self::assertSame([
            'type' => 'perm:car',
            'id' => $id,
            'values' => [
                'name' => 'Family car',
                'price' => 10,
                'chassis' => ['type' => 'perm:chassis', 'id' => null, 'values' => ['weight' => 1000]],
                'labels' => [['type' => 'perm:label', 'id' => null, 'values' => ['text' => 'red']]],
                'maker' => ['type' => 'perm:maker', 'id' => $makerId],
                'document' => null,
            ],
        ], $body['result']);

        [, $admin] = $this->post('coconut-cms:entities.get', json_encode(['id' => $id]), 'admin', 'engine-room');
        self::assertSame(['horsepower' => 300], $admin['result']['values']['engine']['values']);
        self::assertSame('classified', $admin['result']['values']['internal']);

        [$status, $body] = $this->post('coconut-cms:entities.get', '{"id": "nothing"}');
        self::assertSame(404, $status);
        self::assertSame('not_found', $body['error']['code']);
    }

    public function testEntitiesQueryFiltersSortsAndPages(): void {
        $this->savedCar('Alpha', 30);
        $this->savedCar('Bravo', 10);
        $this->savedCar('Charlie', 20);
        $this->savedCar('Delta', 5);

        $query = ['type' => 'perm:car', 'where' => [['path' => 'price', 'operator' => 'greater', 'values' => [5]]], 'orderBy' => [['path' => 'price', 'direction' => 'descending']], 'limit' => 2];
        [$status, $first] = $this->post('coconut-cms:entities.query', json_encode($query));
        self::assertSame(200, $status);
        self::assertSame(['Alpha', 'Charlie'], array_map(static fn (array $item): string => $item['values']['name'], $first['result']['items']));
        self::assertNotNull($first['result']['nextCursor']);

        [, $second] = $this->post('coconut-cms:entities.query', json_encode([...$query, 'after' => $first['result']['nextCursor']]));
        self::assertSame(['Bravo'], array_map(static fn (array $item): string => $item['values']['name'], $second['result']['items']));
        self::assertNull($second['result']['nextCursor']);
    }

    public function testEntitiesQueryLeavesOutWhatTheCallerMayNotRead(): void {
        $system = $this->persistence->as(new SystemActor());
        foreach (['Open' => new PermMaker(), 'Hidden' => new PermSecretMaker()] as $name => $maker) {
            $maker->name = $name;
            $system->save($maker);
        }

        [, $anonymous] = $this->post('coconut-cms:entities.query', '{"type": "perm:maker"}');
        [, $admin] = $this->post('coconut-cms:entities.query', '{"type": "perm:maker", "orderBy": [{"path": "name"}]}', 'admin');

        self::assertSame(['Open'], array_map(static fn (array $item): string => $item['values']['name'], $anonymous['result']['items']));
        self::assertSame(['Hidden', 'Open'], array_map(static fn (array $item): string => $item['values']['name'], $admin['result']['items']));

        // A field it may not read is one it doesn't know of: its name tells nothing.
        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:car", "where": [{"path": "internal", "operator": "equal", "values": ["x"]}]}');
        self::assertSame(400, $status);
        self::assertStringContainsString('has no field "internal"', $body['error']['details']['errors']['']);

        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:secret_maker"}');
        self::assertSame(403, $status);
        self::assertSame('permission_denied', $body['error']['code']);
    }

    public function testBadInputIsNamedByItsPath(): void {
        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:car", "where": [{"path": "price", "operator": "around"}], "limit": "5"}');
        self::assertSame(400, $status);
        self::assertSame(['errors' => ['where.0.operator' => 'Must be one of "equal", "notEqual", "greater", "greaterOrEqual", "less", "lessOrEqual", "in", "isNull", "isNotNull", "like".', 'limit' => 'Must be an integer.']], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:car", "where": [{"path": "price", "operator": "equal"}]}');
        self::assertSame(400, $status);
        self::assertSame(['errors' => ['where.0.values' => 'Takes exactly one value.']], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:car", "where": [{"path": "price", "operator": "equal", "values": ["cheap"]}]}');
        self::assertSame(400, $status);
        self::assertStringContainsString('"price" must hold', $body['error']['details']['errors']['']);

        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": "perm:car", "limit": 1000}');
        self::assertSame(['errors' => ['limit' => 'Must be from 1 to 100.']], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:entities.query', '{"type": ');
        self::assertSame(400, $status);
        self::assertStringStartsWith('The body isn\'t JSON', $body['error']['details']['errors']['']);
    }

    public function testAnUnknownOperationIsNotFound(): void {
        [$status, $body] = $this->post('coconut-cms:nothing', '');

        self::assertSame(404, $status);
        self::assertSame(['kind' => 'operation', 'key' => 'coconut-cms:nothing'], $body['error']['details']);
    }

    public function testAnInternalErrorTellsItsMessageOnlyInDebugMode(): void {
        [$status, $body] = $this->post('api-test:explode', '');
        self::assertSame(500, $status);
        self::assertSame(['code' => 'internal', 'message' => 'Something went wrong on the server.', 'details' => []], $body['error']);

        $debug = Api::boot($this->persistence, $this->authenticator, debug: true);
        $reply = $debug->handle(Request::of(RequestMethod::POST, '/api/api-test:explode'), 'api-test:explode');
        self::assertStringContainsString('hunter2', $reply->body);
    }

    public function testRoutesServePostsUnderThePrefix(): void {
        [$id] = $this->savedCar();
        $router = new Router();
        $this->api->routes($router, prefix: '/admin-api/');

        self::assertFalse($router->dispatch(Request::of(RequestMethod::GET, '/admin-api/coconut-cms:entities.get')));

        ob_start();
        self::assertTrue($router->dispatch(Request::of(RequestMethod::POST, '/admin-api/coconut-cms:entities.get', body: json_encode(['id' => $id]))));
        $body = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Family car', $body['result']['values']['name']);
    }

    public function testTheCommandLineRunsAsSystemWithDottedOptions(): void {
        [$id] = $this->savedCar('Alpha', 30);
        $this->savedCar('Bravo', 10);

        [$status, $out] = $this->command(['coconut-cms:entities.query', '--type=perm:car', '--where.0.path=price', '--where.0.operator=less', '--where.0.values.0=20', '--output=json']);
        self::assertSame(0, $status);
        $page = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Bravo'], array_map(static fn (array $item): string => $item['values']['name'], $page['items']));
        // SystemActor reads every field.
        self::assertSame('classified', $page['items'][0]['values']['internal']);

        [$status, $out] = $this->command(['coconut-cms:entities.get', '--json={"id": "' . $id . '"}']);
        self::assertSame(0, $status);
        self::assertStringContainsString('name      Alpha', $out);
        self::assertStringContainsString('perm:car ' . $id, $out);

        [$status, $out] = $this->command(['coconut-cms:entities.query', '--type=perm:car', '--limit=1']);
        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^ID +TYPE +VALUES/', $out);
        self::assertStringContainsString('--after=', $out);
    }

    public function testTheCommandLineExitsWithEachErrorsStatus(): void {
        [$status, , $err] = $this->command(['coconut-cms:entities.get', '--id=nothing']);
        self::assertSame(4, $status);
        self::assertStringContainsString('"code": "not_found"', $err);

        [$status, , $err] = $this->command(['coconut-cms:entities.query', '--type=perm:car', '--limit=many']);
        self::assertSame(2, $status);
        self::assertStringContainsString('"limit": "Must be an integer."', $err);

        [$status] = $this->command(['coconut-cms:entities.get', '--json={}', '--id=1']);
        self::assertSame(2, $status);
    }

    public function testTheCommandLineListsEveryOperation(): void {
        [$status, $out] = $this->command([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('coconut-cms:entities.query --type [--where] [--orderBy] [--limit] [--after]', $out);
        self::assertStringContainsString('api-test:explode', $out);
    }
}
