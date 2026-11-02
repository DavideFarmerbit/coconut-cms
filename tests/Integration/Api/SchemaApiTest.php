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

        $this->authenticator = new ApiTestAuthenticator();
        $this->authenticator->actor = new PermActor(['architect']);
        $this->newRequest();
    }

    /** The API as a new request boots it: nothing kept in memory from the one before. */
    private function newRequest(): void {
        $persistence = Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, new StrictPolicy(), 'architect');
        $this->api = Api::boot($persistence, $this->authenticator, new Kernel(), new ApiTestCsrf(), debug: true);
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

    public function testAPreviewGivesTheOperationsAndTheirFixupsInOrder(): void {
        $operations = [
            ['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'rooms'],
            ['op' => 'addField', 'type' => 'dy:yacht', 'field' => self::int('masts'), 'default' => 1],
        ];
        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => $operations]);

        self::assertSame(200, $status, json_encode($body));
        self::assertEquals($operations, $body['result']['operations'], 'Written back as given.');
        self::assertSame(['RenameField(dy:yacht, cabins → rooms)', 'AddField(dy:yacht, masts)'], $body['result']['fixups']);
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
        self::assertSame(['addField', 'retypeField'], array_column($body['result']['operations'], 'op'), 'In the order sent.');
        self::assertSame(['kind' => 'coconut-cms:constant', 'value' => 2], $body['result']['operations'][0]['initializer']);
        self::assertSame(['kind' => 'coconut-cms:number-to-text'], $body['result']['operations'][1]['converter']);
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
        self::assertSame(['reason' => 'content_refused', 'failures' => [$buoy => 'TextToNumber can\'t convert "red".'], 'duplicates' => []], $body['error']['details']);
    }

    public function testTheConversionsListedAreThoseFittingTheChange(): void {
        [$status, $body] = $this->post('coconut-cms:schema.conversions', [
            'from' => ['kind' => 'scalar', 'scalarType' => 'string', 'nullable' => false],
            'to' => ['kind' => 'scalar', 'scalarType' => 'int', 'nullable' => false],
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['coconut-cms:text-to-number', 'coconut-cms:map-values'], array_column($body['result']['converters'], 'kind'));
        self::assertSame([], $body['result']['initializers'], 'A retype takes converters only.');
        self::assertSame(
            ['name' => 'mapping', 'type' => 'map', 'nullable' => false, 'optional' => false, 'default' => null, 'choices' => null],
            $body['result']['converters'][1]['parameters'][0],
        );
        $converter = $body['result']['converters'][0];
        self::assertSame(['oneOf' => [
            ['kind' => 'scalar', 'scalarType' => 'string', 'target' => null, 'item' => null],
            ['kind' => 'scalar', 'scalarType' => 'text', 'target' => null, 'item' => null],
        ]], $converter['from']);
        self::assertSame([
            ['name' => 'onFailure', 'type' => 'enum', 'nullable' => false, 'optional' => false, 'default' => null, 'choices' => ['default', 'cancel']],
        ], $converter['parameters'], 'The number type is the new field\'s: nothing to ask.');

        [, $body] = $this->post('coconut-cms:schema.conversions', ['to' => ['kind' => 'scalar', 'scalarType' => 'int', 'nullable' => false]]);
        self::assertSame(
            ['coconut-cms:text-to-number', 'coconut-cms:number-to-number', 'coconut-cms:map-values', 'coconut-cms:first-item'],
            array_column($body['result']['converters'], 'kind'),
        );
        self::assertSame(['coconut-cms:constant', 'coconut-cms:copy'], array_column($body['result']['initializers'], 'kind'));

        [, $body] = $this->post('coconut-cms:schema.conversions', []);
        self::assertCount(11, $body['result']['converters']);
        self::assertSame([], $body['result']['levelInitializers'], 'No module registers one here.');
    }

    public function testTheFieldRulesListedAreThoseFittingTheField(): void {
        $core = static fn (array $rules): array => array_values(array_filter(array_column($rules, 'rule'), static fn (string $rule): bool => str_starts_with($rule, 'coconut-cms:')));

        [$status, $body] = $this->post('coconut-cms:schema.validators', ['field' => ['kind' => 'scalar', 'scalarType' => 'decimal', 'nullable' => false]]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['coconut-cms:range'], $core($body['result']['fieldRules']));
        $range = $body['result']['fieldRules'][array_search('coconut-cms:range', array_column($body['result']['fieldRules'], 'rule'), true)];
        self::assertSame([
            ['name' => 'min', 'type' => 'float', 'nullable' => true, 'optional' => true, 'default' => null, 'choices' => null],
            ['name' => 'max', 'type' => 'float', 'nullable' => true, 'optional' => true, 'default' => null, 'choices' => null],
        ], $range['parameters']);

        [, $body] = $this->post('coconut-cms:schema.validators', ['field' => ['kind' => 'collection', 'item' => ['kind' => 'scalar', 'scalarType' => 'string', 'nullable' => false]]]);
        self::assertSame(['coconut-cms:max-items', 'coconut-cms:min-items', 'coconut-cms:on-items'], $core($body['result']['fieldRules']));
        $onItems = $body['result']['fieldRules'][array_search('coconut-cms:on-items', array_column($body['result']['fieldRules'], 'rule'), true)];
        self::assertSame(['kind' => 'collection', 'scalarType' => null, 'target' => null, 'item' => null], $onItems['fits']);
        self::assertSame([['name' => 'rules', 'type' => 'field_rules', 'nullable' => false, 'optional' => true, 'default' => [], 'choices' => null]], $onItems['parameters']);

        [, $body] = $this->post('coconut-cms:schema.validators', []);
        self::assertSame(['coconut-cms:max-length', 'coconut-cms:range', 'coconut-cms:pattern', 'coconut-cms:max-items', 'coconut-cms:min-items', 'coconut-cms:on-items'], $core($body['result']['fieldRules']));
        self::assertIsArray($body['result']['prototypeRules']);
    }

    public function testRulesSetOnAnEditorTypeCheckItsNextSave(): void {
        $operations = [
            ['op' => 'setFieldRules', 'type' => 'dy:buoy', 'field' => 'color', 'rules' => [
                ['rule' => 'coconut-cms:max-length', 'config' => ['max' => 5]],
                ['rule' => 'coconut-cms:pattern', 'config' => ['regex' => '^[a-z]+$']],
            ]],
            ['op' => 'setFieldPermission', 'type' => 'dy:buoy', 'field' => 'color', 'read' => null, 'write' => 'architect'],
            ['op' => 'setTypePermission', 'type' => 'dy:buoy', 'read' => null, 'create' => 'architect', 'update' => null, 'delete' => null],
        ];
        [$status, $body] = $this->save($operations);
        self::assertSame(200, $status, json_encode($body));
        self::assertEquals($operations, $body['result']['operations'], 'Written back as given.');
        self::assertSame([], $body['result']['fixups'], 'Rules and permissions change only definitions.');

        $this->newRequest();
        [$status, $body] = $this->post('coconut-cms:entities.create', ['type' => 'dy:buoy', 'changes' => ['color' => 'Magenta']]);
        self::assertSame(422, $status, json_encode($body));
        self::assertSame(['color' => ['must be at most 5 characters', 'must match ^[a-z]+$']], $body['error']['details']['errors'], 'Read back from the stored schema.');

        $this->authenticator->actor = new PermActor(['editor']);
        [$status] = $this->post('coconut-cms:entities.create', ['type' => 'dy:buoy', 'changes' => ['color' => 'blue']]);
        self::assertSame(403, $status, 'Only an architect creates buoys now.');
    }

    public function testAPreviewSamplesTheFirstEntriesThatChange(): void {
        $first = $this->store('dy:yacht', ['name' => 'Sea', 'cabins' => 4]);
        $second = $this->store('dy:yacht', ['name' => 'Wind', 'cabins' => 2]);
        $this->store('dy:yacht', ['name' => 'Calm', 'cabins' => 6]);
        $operations = [['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'rooms']];

        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => $operations, 'samples' => 2]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame([
            ['id' => $first, 'type' => 'dy:yacht', 'before' => ['cabins' => 4], 'after' => ['rooms' => 4]],
            ['id' => $second, 'type' => 'dy:yacht', 'before' => ['cabins' => 2], 'after' => ['rooms' => 2]],
        ], $body['result']['samples'], 'The first two, in id order.');

        [, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => $operations]);
        self::assertCount(3, $body['result']['samples'], 'Three by default.');

        [, $body] = $this->save($operations);
        self::assertSame([], $body['result']['samples'], 'A save samples nothing.');

        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 2, 'operations' => [], 'samples' => 21]);
        self::assertSame(400, $status);
        self::assertSame(['samples'], array_keys($body['error']['details']['errors']), json_encode($body));
    }

    public function testNestedRulesOnAnAddedCollectionCheckEachItem(): void {
        [$status, $body] = $this->save([
            ['op' => 'addField', 'type' => 'dy:buoy', 'field' => ['name' => 'tags', 'kind' => 'collection', 'item' => ['kind' => 'scalar', 'scalarType' => 'string', 'nullable' => false]], 'default' => []],
            ['op' => 'setFieldRules', 'type' => 'dy:buoy', 'field' => 'tags', 'rules' => [
                ['rule' => 'coconut-cms:max-items', 'config' => ['max' => 2]],
                ['rule' => 'coconut-cms:on-items', 'config' => ['rules' => [['rule' => 'coconut-cms:pattern', 'config' => ['regex' => '^[a-z]+$']]]]],
            ]],
        ]);
        self::assertSame(200, $status, json_encode($body));

        $this->newRequest();
        [$status, $body] = $this->post('coconut-cms:entities.create', ['type' => 'dy:buoy', 'changes' => ['tags' => ['ok', 'BAD', 'x']]]);
        self::assertSame(422, $status, json_encode($body));
        self::assertSame(['tags' => ['must hold at most 2 items'], 'tags.1' => ['must match ^[a-z]+$']], $body['error']['details']['errors']);
    }

    public function testADefaultBreakingARuleIsReportedNeverRefused(): void {
        $operations = [
            ['op' => 'setFieldRules', 'type' => 'dy:buoy', 'field' => 'color', 'rules' => [['rule' => 'coconut-cms:max-length', 'config' => ['max' => 2]]]],
        ];
        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => $operations]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame([['type' => 'dy:buoy', 'path' => 'color', 'error' => 'must be at most 2 characters']], $body['result']['defaultBreaks'], 'The default "red" is too long.');

        [$status, $body] = $this->save($operations);
        self::assertSame(200, $status, 'A placeholder default is allowed.');
        self::assertSame('color', $body['result']['defaultBreaks'][0]['path']);

        [, $body] = $this->save([['op' => 'renameField', 'type' => 'dy:yacht', 'old' => 'cabins', 'new' => 'rooms']], revision: 2);
        self::assertSame([], $body['result']['defaultBreaks'], 'Only the types a save changes are checked.');
    }

    public function testARuleThatDoesntFitNamesItsPlace(): void {
        [$status, $body] = $this->save([['op' => 'setFieldRules', 'type' => 'dy:buoy', 'field' => 'color', 'rules' => [
            ['rule' => 'coconut-cms:on-items', 'config' => ['rules' => [['rule' => 'coconut-cms:max-length', 'config' => []]]]],
        ]]]);
        self::assertSame(400, $status);
        self::assertSame(['operations.0.rules.0.config.rules.0.config.max'], array_keys($body['error']['details']['errors']), json_encode($body));

        [$status, $body] = $this->save([['op' => 'setFieldRules', 'type' => 'dy:buoy', 'field' => 'color', 'rules' => [['rule' => 'coconut-cms:range', 'config' => ['max' => 3]]]]]);
        self::assertSame(400, $status);
        self::assertStringContainsString('Rule 0: ', $body['error']['details']['errors']['operations.0'] ?? json_encode($body));
    }

    public function testOnlyTheSchemaEditorRoleChangesTypes(): void {
        $this->authenticator->actor = new PermActor(['editor']);

        [$status, $body] = $this->post('coconut-cms:schema.preview', ['revision' => 1, 'operations' => []]);

        self::assertSame(403, $status);
        self::assertSame('permission_denied', $body['error']['code']);
    }
}
