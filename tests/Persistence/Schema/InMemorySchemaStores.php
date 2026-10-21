<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * A schema store, a value store and a registry, all on one fresh in-memory
 * SQLite connection, so a compile commits to both stores in one transaction,
 * as it would against a real database.
 */
final class InMemorySchemaStores
{
    public readonly Connection $connection;
    public readonly SchemaStore $schemas;
    public readonly SchemaFieldValueStore $values;
    public readonly SchemaRegistry $registry;
    public readonly FieldValidatorRegistry $fieldValidators;
    public readonly PrototypeValidatorRegistry $prototypeValidators;
    public readonly ValueObjectRegistry $valueObjects;

    public function __construct() {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->fieldValidators = new FieldValidatorRegistry();
        $this->prototypeValidators = new PrototypeValidatorRegistry();
        $this->valueObjects = new ValueObjectRegistry();
        $this->schemas = new SchemaStore($this->connection, new EntitySchemaCodec($this->fieldValidators, $this->prototypeValidators, $this->valueObjects));
        $this->schemas->createTable();
        $this->values = new SchemaFieldValueStore($this->connection);
        $this->values->createTable();
        $this->registry = new SchemaRegistry($this->schemas);
    }

    public function compiler(): SchemaCompiler {
        return new SchemaCompiler($this->registry, $this->values, $this->schemas);
    }
}
