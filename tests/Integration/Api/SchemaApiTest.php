<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Api;

use XyloIsCoding\CoconutCms\Api\Api;
use XyloIsCoding\CoconutCms\Core\Kernel;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RequestMethod;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermActor;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermissionFixtures;

/**
 * schema.preview and schema.save over HTTP: operations read from JSON,
 * written back normalized, and each refusal with its code. Only an
 * "architect" may change types; the database is at revision 1.
 */
final class SchemaApiTest extends DynamicTestCase
{
    private ApiTestAuthenticator $authenticator;
    private Api $api;

    protected function setUp(): void {
        parent::setUp();
        // The fixture files hold several classes: load them by their own.
        class_exists(ApiTest::class);
        class_exists(PermissionFixtures::class);

        $persistence = Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, new StrictPolicy(), 'architect');
        $this->authenticator = new ApiTestAuthenticator();
        $this->authenticator->actor = new PermActor(['architect']);
        $this->api = Api::boot($persistence, $this->authenticator, new Kernel(), new ApiTestCsrf());
    }

    /**
     * POSTs $input to $operation as JSON.
     * @param array<string, mixed> $input
     * @return array{int, array<string, mixed>} The status and the decoded body.
     */
    private function post(string $operation, array $input): array {
        $reply = $this->api->handle(Request::of(RequestMethod::POST, '/api/' . $operation, body: json_encode($input), headers: ['x-csrf-token' => ApiTestCsrf::TOKEN]), $operation);

        return [$reply->status, json_decode($reply->body, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @param list<array<string, mixed>> $operations */
    private function save(array $operations, int $revision = 1): array {
        return $this->post('coconut-cms:schema.save', ['revision' => $revision, 'operations' => $operations]);
    }

    private static function int(string $name): array {
        return ['name' => $name, 'kind' => 'scalar', 'scalarType' => 'int', 'nullable' => false];
    }

    public function testAPreviewGivesTheOperationsNormalizedAsJson(): void {
        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => [
            ['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'rooms'],
            ['op' => 'addField', 'type' => 'dy:yacht', 'field' => self::int('masts'), 'default' => 1],
            ['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'rooms', 'new' => 'berths'],
        ]]);

        self::assertSame(200, $status, json_encode($body));
        self::assertEquals([
            ['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'berths'],
            ['op' => 'addField', 'type' => 'dy:yacht', 'field' => self::int('masts'), 'default' => 1],
        ], $body['result']['operations']);
        self::assertSame(['RenameField(dy:yacht, cabins → berths)', 'AddField(dy:yacht, masts)'], $body['result']['fixups']);
        self::assertSame(1, $body['result']['revision']);
        self::assertSame(1, (new MigrationState($this->connection))->revision(), 'Nothing ran.');
    }

    public function testASaveMovesTheContentWithARegisteredInitializer(): void {
        $yacht = $this->store('dy:yacht', ['name' => 'Sea']);

        [$status, $body] = $this->save([
            ['op' => 'addField', 'type' => 'dy:yacht', 'field' => self::int('masts'), 'default' => 1, 'initializer' => ['kind' => 'coconut-cms:constant', 'value' => 2]],
            ['op' => 'retypeField', 'type' => 'dy:yacht', 'field' => ['name' => 'cabins', 'kind' => 'scalar', 'scalarType' => 'string', 'nullable' => false], 'converter' => ['kind' => 'coconut-cms:number-to-text']],
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(2, $body['result']['revision']);
        self::assertSame(['retypeField', 'addField'], array_column($body['result']['operations'], 'op'), 'A stored field\'s change, then the new field.');
        self::assertSame(['kind' => 'coconut-cms:constant', 'value' => 2], $body['result']['operations'][1]['initializer']);
        self::assertSame(['kind' => 'coconut-cms:number-to-text'], $body['result']['operations'][0]['converter']);
        $loaded = $this->session()->loadDynamic($yacht);
        self::assertSame(2, $loaded->get('masts'));
        self::assertSame('3', $loaded->get('cabins'));
    }

    public function testInputThatDoesntFitNamesItsPath(): void {
        $cases = [
            [['op' => 'shrinkType', 'type' => 'dy:yacht'], 'operations.0.op'],
            [['op' => 'addField', 'type' => 'dy:yacht', 'field' => ['name' => 'masts', 'kind' => 'blob'], 'default' => 1], 'operations.0.field.kind'],
            [['op' => 'addField', 'type' => 'dy:yacht', 'field' => self::int('masts'), 'default' => 1, 'colour' => 'red'], 'operations.0.colour'],
            [['op' => 'retypeField', 'type' => 'dy:buoy', 'field' => self::int('color'), 'converter' => ['kind' => 'coconut-cms:text-to-number']], 'operations.0.converter.onFailure'],
            [['op' => 'retypeField', 'type' => 'dy:buoy', 'field' => self::int('color'), 'converter' => ['kind' => 'shop:nothing']], 'operations.0.converter.kind'],
            [['op' => 'removeField', 'type' => 'dy:yacht', 'field' => 'not a name!'], 'operations.0'],
        ];
        foreach ($cases as [$operation, $path]) {
            [$status, $body] = $this->save([$operation]);

            self::assertSame(400, $status, $path);
            self::assertSame([$path], array_keys($body['error']['details']['errors']), json_encode($body));
        }
    }

    public function testAnOperationThatCantApplyNamesItsIndex(): void {
        [$status, $body] = $this->save([
            ['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'berths'],
            ['op' => 'removeField', 'type' => 'dy:yacht', 'field' => 'cabins'],
        ]);

        self::assertSame(400, $status);
        self::assertSame('invalid_input', $body['error']['code']);
        self::assertSame(['operations.1'], array_keys($body['error']['details']['errors']));
    }

    public function testAStaleRevisionIsAConflict(): void {
        [$status, $body] = $this->save([['op' => 'deleteType', 'type' => 'dy:buoy']], revision: 0);

        self::assertSame(409, $status);
        self::assertSame(['code' => 'stale_revision', 'sent' => 0, 'current' => 1], ['code' => $body['error']['code'], ...$body['error']['details']]);
    }

    public function testEntriesThatCantMoveRefuseTheSave(): void {
        $this->save([['op' => 'setDefault', 'type' => 'dy:buoy', 'path' => 'color', 'value' => '7']]);
        $buoy = $this->store('dy:buoy', ['color' => 'red']);

        [$status, $body] = $this->save([[
            'op' => 'retypeField', 'type' => 'dy:buoy', 'field' => self::int('color'),
            'converter' => ['kind' => 'coconut-cms:text-to-number', 'onFailure' => 'cancel'],
        ]], revision: 2);

        self::assertSame(422, $status);
        self::assertSame('schema_save_refused', $body['error']['code']);
        self::assertSame(['reason' => 'entries_fail', 'failures' => [$buoy => 'TextToNumber can\'t convert "red".']], $body['error']['details']);
    }

    public function testTheConversionsListedAreThoseFittingTheChange(): void {
        [$status, $body] = $this->post('coconut-cms:schema.conversions', [
            'from' => ['kind' => 'scalar', 'scalarType' => 'string', 'nullable' => false],
            'to' => ['kind' => 'scalar', 'scalarType' => 'int', 'nullable' => false],
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['coconut-cms:text-to-number'], array_column($body['result']['converters'], 'kind'));
        self::assertSame([], $body['result']['initializers'], 'A retype takes converters only.');
        $converter = $body['result']['converters'][0];
        self::assertSame(['oneOf' => [
            ['kind' => 'scalar', 'scalarType' => 'string', 'target' => null, 'item' => null],
            ['kind' => 'scalar', 'scalarType' => 'text', 'target' => null, 'item' => null],
        ]], $converter['from']);
        self::assertSame([
            ['name' => 'onFailure', 'type' => 'enum', 'nullable' => false, 'optional' => false, 'default' => null, 'choices' => ['default', 'cancel']],
        ], $converter['parameters'], 'The number type is the new field\'s: nothing to ask.');

        [, $body] = $this->post('coconut-cms:schema.conversions', ['to' => ['kind' => 'scalar', 'scalarType' => 'int', 'nullable' => false]]);
        self::assertSame(['coconut-cms:text-to-number'], array_column($body['result']['converters'], 'kind'));
        self::assertSame(['coconut-cms:constant', 'coconut-cms:copy'], array_column($body['result']['initializers'], 'kind'));

        [, $body] = $this->post('coconut-cms:schema.conversions', []);
        self::assertCount(4, $body['result']['converters']);
    }

    public function testOnlyTheSchemaEditorRoleChangesTypes(): void {
        $this->authenticator->actor = new PermActor(['editor']);

        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => []]);

        self::assertSame(403, $status);
        self::assertSame('permission_denied', $body['error']['code']);
    }
}
