<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\FieldIdentities;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldIdentity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

final class FieldIdentitiesTest extends TestCase
{
    private static function field(string $name, ?FieldIdentity $identity = null): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, Nullability::NotNullable)->withIdentity($identity);
    }

    /** @param list<FieldDescriptor> $fields */
    private static function schema(array $fields): EntitySchema {
        $collection = FieldDescriptorCollection::empty();
        foreach ($fields as $field) {
            $collection = $collection->with(FieldName::of($field->name()), $field);
        }

        return new EntitySchema('test:car', null, 'car', false, $collection, FieldPermissionCollection::empty(), FieldValidatorCollection::empty(), [], []);
    }

    /** @param list<FieldDescriptor> $fields */
    private static function batch(array $fields): CompiledBatch {
        $schema = self::schema($fields);

        return new CompiledBatch(['test:car' => $schema], [], [new StoredSchema($schema, SchemaSource::Native, 'fingerprint', [])]);
    }

    private static function identityOf(CompiledBatch $batch, string $field): FieldIdentity {
        return $batch->schemas['test:car']->fields->get(FieldName::of($field))->identity();
    }

    public function testAFieldNewToWhatsKnownGetsAGeneratedIdAndColumn(): void {
        $identity = self::identityOf(FieldIdentities::assign(self::batch([self::field('price')]), []), 'price');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $identity->id);
        self::assertMatchesRegularExpression('/^f_[0-9a-f]{6}$/', $identity->column);
    }

    public function testAKnownFieldKeepsItsIdentity(): void {
        $known = new FieldIdentity('stable-id', 'f_abcdef');
        $assigned = FieldIdentities::assign(self::batch([self::field('price')]), ['test:car' => self::schema([self::field('price', $known)])]);

        self::assertEquals($known, self::identityOf($assigned, 'price'));
    }

    public function testAFieldWithAnIdAndNoColumnKeepsItsIdAndGetsANewColumn(): void {
        $known = new FieldIdentity('stable-id', null);
        $identity = self::identityOf(FieldIdentities::assign(self::batch([self::field('price')]), ['test:car' => self::schema([self::field('price', $known)])]), 'price');

        self::assertSame('stable-id', $identity->id);
        self::assertNotNull($identity->column);
    }

    public function testWhatTheBatchFieldAlreadyHoldsWins(): void {
        $own = new FieldIdentity('own-id', 'f_111111');
        $known = new FieldIdentity('known-id', 'f_222222');
        $assigned = FieldIdentities::assign(self::batch([self::field('price', $own)]), ['test:car' => self::schema([self::field('price', $known)])]);

        self::assertEquals($own, self::identityOf($assigned, 'price'));
    }

    public function testNoTwoFieldsShareAColumn(): void {
        $fields = array_map(static fn (int $index): FieldDescriptor => self::field('field' . $index), range(1, 500));
        $assigned = FieldIdentities::assign(self::batch($fields), []);

        $columns = [];
        foreach ($assigned->schemas['test:car']->fields as $field) {
            $columns[] = $field->identity()->column;
        }

        self::assertCount(500, array_unique($columns));
    }

    public function testTheStoredFormOfTheBatchHoldsTheSameIdentities(): void {
        $assigned = FieldIdentities::assign(self::batch([self::field('price')]), []);

        self::assertSame($assigned->schemas['test:car'], $assigned->stored[0]->schema);
        self::assertSame('fingerprint', $assigned->stored[0]->fingerprint);
    }

    public function testTheSameFieldInTwoDatabasesGetsItsOwnId(): void {
        $first = self::identityOf(FieldIdentities::assign(self::batch([self::field('price')]), []), 'price');
        $second = self::identityOf(FieldIdentities::assign(self::batch([self::field('price')]), []), 'price');

        self::assertNotSame($first->id, $second->id);
    }

    public function testTwoFieldsDifferingOnlyInWhereTheyAreStoredAreTheSameField(): void {
        $stored = self::field('price', new FieldIdentity('id', 'f_abcdef'));

        self::assertTrue($stored->sameAs(self::field('price')));
        self::assertFalse($stored->sameAs(FieldDescriptor::scalar('price', ScalarType::String, Nullability::NotNullable)));
    }

    public function testAFieldKeepsItsIdentityThroughEveryCopy(): void {
        $identity = new FieldIdentity('id', 'f_abcdef');
        $list = FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::String, Nullability::NotNullable))->withIdentity($identity);

        self::assertSame($identity, self::field('price', $identity)->renamed('cost')->identity());
        self::assertSame($identity, self::field('price', $identity)->withNullability(Nullability::Nullable)->identity());
        self::assertSame($identity, $list->renamed('labels')->identity());
        self::assertSame($identity, $list->withNullability(Nullability::Nullable)->identity());
        self::assertSame($identity, FieldDescriptor::reference('maker', 'test:maker')->withIdentity($identity)->retargeted('test:maker', 'test:brand')->identity());
    }
}
