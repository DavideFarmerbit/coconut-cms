<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Api\Auth\AnonymousActor;
use XyloIsCoding\CoconutCms\Api\Auth\Authenticator;
use XyloIsCoding\CoconutCms\Api\Auth\CsrfGuard;
use XyloIsCoding\CoconutCms\Api\Auth\SignInFailed;
use XyloIsCoding\CoconutCms\Api\Auth\TooManyAttempts;
use XyloIsCoding\CoconutCms\Api\Auth\User;
use XyloIsCoding\CoconutCms\Api\Operations\Auth\Me;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Core\Kernel;
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

/** Whoever the test says calls; one user, "ada", signs in with "secret". */
final class ApiTestAuthenticator implements Authenticator
{
    public Actor $actor;

    public function __construct() {
        $this->actor = new AnonymousActor();
    }

    public function actorFor(Request $request): Actor {
        return $this->actor;
    }

    public function signIn(string $login, string $password, bool $remember): User {
        if ($login === 'many') {
            throw new TooManyAttempts();
        }

        if ($login !== 'ada' || $password !== 'secret') {
            throw new SignInFailed();
        }

        return $this->actor = $this->userNamed('ada');
    }

    public function signOut(): void {
        $this->actor = new AnonymousActor();
    }

    public function userNamed(string $login): ?User {
        return $login === 'ada' ? new User('1', 'ada', ['admin', 'engine-room']) : null;
    }
}

/** A token fixed by the test. */
final readonly class ApiTestCsrf implements CsrfGuard
{
    public const TOKEN = 'test-token';

    public function token(): string {
        return self::TOKEN;
    }

    public function isValid(?string $token): bool {
        return $token === self::TOKEN;
    }
}

/** Reads a service from the kernel. */
#[Operation('api-test:greet')]
final readonly class ApiTestGreet
{
    public function run(ListTypesInput $input, OperationContext $context): Me {
        $greeting = $context->kernel->get(Identifier::of('api-test', 'greeting'));

        return new Me(new User('0', $greeting->text, []));
    }
}

/** A service bound in the test's kernel. */
final readonly class ApiTestGreeting
{
    public function __construct(public string $text) {
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
        return [ApiTestExplode::class, ApiTestGreet::class];
    }

    public function adminCode(): ?AdminCode {
        return null;
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
        $kernel = new Kernel();
        $kernel->bind(Identifier::of('api-test', 'greeting'), static fn (): ApiTestGreeting => new ApiTestGreeting('hello'));
        $this->api = Api::boot($this->persistence, $this->authenticator, $kernel, new ApiTestCsrf());
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

        return $this->send($operation, $body);
    }

    /**
     * POSTs $body to $operation as whoever the authenticator holds now.
     * @return array{int, array<string, mixed>} The status and the decoded body.
     */
    private function send(string $operation, string $body, ?string $token = ApiTestCsrf::TOKEN): array {
        $headers = $token === null ? [] : ['x-csrf-token' => $token];
        $reply = $this->api->handle(Request::of(RequestMethod::POST, '/api/' . $operation, body: $body, headers: $headers), $operation);

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

        // A new entity's values, as a read gives them, inherited fields included; unreadable ones left out.
        [, $body] = $this->post('coconut-cms:types.get', '{"type": "perm:car"}');
        self::assertSame('', $body['result']['defaults']['name']);
        self::assertSame(['type' => 'perm:chassis', 'id' => null, 'values' => ['weight' => 1000]], $body['result']['defaults']['chassis']);
        self::assertArrayNotHasKey('internal', $body['result']['defaults']);
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

        $debug = Api::boot($this->persistence, $this->authenticator, csrf: new ApiTestCsrf(), debug: true);
        $reply = $debug->handle(Request::of(RequestMethod::POST, '/api/api-test:explode', headers: ['X-CSRF-Token' => ApiTestCsrf::TOKEN]), 'api-test:explode');
        self::assertStringContainsString('hunter2', $reply->body);
    }

    public function testACallWithoutTheSessionsCsrfTokenIsRefused(): void {
        [$status, $body] = $this->send('coconut-cms:auth.me', '', token: null);
        self::assertSame(403, $status);
        self::assertSame('csrf_failed', $body['error']['code']);

        [$status] = $this->send('coconut-cms:auth.me', '', token: 'forged');
        self::assertSame(403, $status);

        self::assertSame(ApiTestCsrf::TOKEN, $this->api->csrfToken());
    }

    public function testSigningInAndOutGoesThroughTheAuthenticator(): void {
        [$status, $body] = $this->send('coconut-cms:auth.me', '');
        self::assertSame(200, $status);
        self::assertSame(['user' => null], $body['result']);

        [$status, $body] = $this->send('coconut-cms:auth.signIn', '{"login": "ada", "password": "wrong"}');
        self::assertSame(401, $status);
        self::assertSame('sign_in_failed', $body['error']['code']);

        [$status, $body] = $this->send('coconut-cms:auth.signIn', '{"login": "many", "password": "x"}');
        self::assertSame(429, $status);
        self::assertSame('too_many_requests', $body['error']['code']);

        [$status, $body] = $this->send('coconut-cms:auth.signIn', '{"login": "ada", "password": "secret", "remember": true}');
        self::assertSame(200, $status);
        self::assertSame(['id' => '1', 'name' => 'ada', 'roles' => ['admin', 'engine-room']], $body['result']['user']);

        [, $body] = $this->send('coconut-cms:auth.me', '');
        self::assertSame('ada', $body['result']['user']['name']);

        [, $body] = $this->send('coconut-cms:auth.signOut', '');
        self::assertSame(['user' => null], $body['result']);
        [, $body] = $this->send('coconut-cms:auth.me', '');
        self::assertNull($body['result']['user']);
    }

    public function testTheCommandLineActsAsAUserWithAs(): void {
        [$id] = $this->savedCar();

        [$status, $out] = $this->command(['coconut-cms:auth.me', '--as=ada', '--output=json']);
        self::assertSame(0, $status);
        self::assertSame('ada', json_decode($out, true, 512, JSON_THROW_ON_ERROR)['user']['name']);

        // As ada, an admin, the internal note shows; as SystemActor too.
        [, $out] = $this->command(['coconut-cms:entities.get', '--id=' . $id, '--as=ada', '--output=json']);
        self::assertSame('classified', json_decode($out, true, 512, JSON_THROW_ON_ERROR)['values']['internal']);

        [$status, , $err] = $this->command(['coconut-cms:auth.me', '--as=nobody']);
        self::assertSame(4, $status);
        self::assertStringContainsString('There is no user "nobody"', $err);
    }

    public function testAnOperationReachesTheProjectsServicesThroughTheKernel(): void {
        [$status, $body] = $this->send('api-test:greet', '');

        self::assertSame(200, $status);
        self::assertSame('hello', $body['result']['user']['name']);
    }

    public function testRoutesServePostsUnderThePrefix(): void {
        [$id] = $this->savedCar();
        $router = new Router();
        $this->api->routes($router, prefix: '/admin-api/');

        self::assertFalse($router->dispatch(Request::of(RequestMethod::GET, '/admin-api/coconut-cms:entities.get')));

        ob_start();
        self::assertTrue($router->dispatch(Request::of(RequestMethod::POST, '/admin-api/coconut-cms:entities.get', body: json_encode(['id' => $id]), headers: ['X-CSRF-Token' => ApiTestCsrf::TOKEN])));
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

    public function testEntitiesCreateAndUpdateChangeValuesByPath(): void {
        [$status, $created] = $this->post('coconut-cms:entities.create', '{"type": "perm:car", "changes": {"name": "New", "chassis:weight": 1300, "labels": [{"values": {"text": "blue"}}]}}');
        self::assertSame(200, $status);
        $id = $created['result']['id'];
        self::assertSame('New', $created['result']['values']['name']);
        self::assertSame(1300, $created['result']['values']['chassis']['values']['weight']);
        self::assertSame([['type' => 'perm:label', 'id' => null, 'values' => ['text' => 'blue']]], $created['result']['values']['labels']);

        [$status, $updated] = $this->post('coconut-cms:entities.update', json_encode(['id' => $id, 'changes' => ['name' => 'Renamed', 'labels.0.text' => 'green']]));
        self::assertSame(200, $status);
        self::assertSame('Renamed', $updated['result']['values']['name']);
        self::assertSame(1300, $updated['result']['values']['chassis']['values']['weight']);
        self::assertSame('green', $updated['result']['values']['labels'][0]['values']['text']);
    }

    public function testAWriteFailsTheWayEachRuleSays(): void {
        [$id] = $this->savedCar();

        [$status, $body] = $this->post('coconut-cms:entities.update', json_encode(['id' => $id, 'changes' => ['colour' => 'red']]));
        self::assertSame(400, $status);
        self::assertSame(['errors' => ['changes.colour' => '"perm:car" has no field "colour".']], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:entities.update', json_encode(['id' => $id, 'changes' => ['price' => 99]]));
        self::assertSame(403, $status);
        self::assertSame(['type' => 'perm:car', 'action' => null, 'paths' => ['price']], $body['error']['details']);

        [$status, $body] = $this->post('coconut-cms:entities.create', '{"type": "perm:document"}');
        self::assertSame(403, $status);
        self::assertSame('create', $body['error']['details']['action']);

        [$status, $body] = $this->post('coconut-cms:entities.create', '{"type": "perm:car", "changes": {"name": "Secret"}}', 'admin');
        self::assertSame(422, $status);
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(['' => ['a secret car needs an internal note']], $body['error']['details']['errors']);

        [$status] = $this->post('coconut-cms:entities.create', '{"type": "perm:nothing"}');
        self::assertSame(404, $status);

        [$status] = $this->post('coconut-cms:entities.update', '{"id": "nothing", "changes": {}}');
        self::assertSame(404, $status);
    }

    public function testEntitiesDeleteRemovesAStandaloneEntity(): void {
        [$id] = $this->savedCar();
        $engineId = $this->persistence->as(new SystemActor())->read($id)->values['engine']->id;

        [$status] = $this->post('coconut-cms:entities.delete', json_encode(['id' => $engineId]), 'admin', 'engine-room');
        self::assertSame(404, $status);

        [$status, $body] = $this->post('coconut-cms:entities.delete', json_encode(['id' => $id]), 'admin', 'engine-room');
        self::assertSame(200, $status);
        self::assertSame(['id' => $id], $body['result']);

        [$status] = $this->post('coconut-cms:entities.get', json_encode(['id' => $id]));
        self::assertSame(404, $status);
    }

    public function testTheCommandLineTakesChangesByPath(): void {
        [$status, $out] = $this->command(['coconut-cms:entities.create', '--type=perm:car', '--changes.name=Cli', '--changes.engine={"type": "perm:engine"}', '--output=json']);
        self::assertSame(0, $status);
        $id = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['id'];

        [$status, $out] = $this->command(['coconut-cms:entities.update', '--id=' . $id, '--changes.engine.horsepower=301', '--changes.chassis:weight=1500', '--output=json']);
        self::assertSame(0, $status);
        $car = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(301, $car['values']['engine']['values']['horsepower']);
        self::assertSame(1500, $car['values']['chassis']['values']['weight']);
    }

    public function testTheCommandLineListsEveryOperation(): void {
        [$status, $out] = $this->command([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('coconut-cms:entities.query --type [--where] [--orderBy] [--limit] [--after]', $out);
        self::assertStringContainsString('api-test:explode', $out);
    }
}
