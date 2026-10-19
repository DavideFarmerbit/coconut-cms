<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

#[Entity]
class CompilerRoot
{
    #[Scalar(ScalarType::String)]
    private string $name = '';
}

#[Entity]
final class CompilerChild extends CompilerRoot
{
    #[Scalar(ScalarType::String)]
    private string $extra = '';
}

#[Entity]
final class CompilerEmbedTarget
{
    #[Scalar(ScalarType::String)]
    private string $value = '';
}

#[Entity]
final class CompilerEmbedder
{
    #[Embed(CompilerEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedMiddle
{
    #[Embed(CompilerEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedOuter
{
    #[Embed(CompilerDeepEmbedMiddle::class)]
    private object $middle;

    public function __construct() {
        $this->middle = new CompilerDeepEmbedMiddle();
    }
}

#[Entity]
final class CompilerCollectionEmbedder
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerEmbedTarget::class)]
    private array $items = [];
}

#[Entity]
final class CompilerCycleA
{
    #[Embed(CompilerCycleB::class)]
    private ?object $b = null;
}

#[Entity]
final class CompilerCycleB
{
    #[Embed(CompilerCycleA::class)]
    private ?object $a = null;
}

#[Entity]
final class CompilerNode
{
    #[OwningReference(CompilerNode::class)]
    private ?object $child = null;
}

final class SchemaCompilerTest extends TestCase
{
    private SchemaCompiler $compiler;

    protected function setUp(): void {
        $this->compiler = self::newCompiler(new SchemaRegistry());
    }

    private static function newCompiler(SchemaRegistry $registry): SchemaCompiler {
        $valueStore = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $valueStore->createTable();

        return new SchemaCompiler($registry, $valueStore);
    }

    /**
     * Stands in for whatever a real caller does before handing a batch to
     * compile(): reading each native class's own blueprint. compile() itself
     * never does this.
     * @param class-string[] $classes
     * @return array<string, \XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint>
     */
    private static function blueprintsOf(array $classes): array {
        $blueprints = [];
        foreach ($classes as $class) {
            $blueprints[$class] = NativeEntityReader::read($class);
        }

        return $blueprints;
    }

    public function testCompileOrdersParentBeforeChild(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerChild::class, CompilerRoot::class])));

        self::assertSame([CompilerRoot::class, CompilerChild::class], $order);
    }

    public function testCompileOrdersEmbedTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame([CompilerEmbedTarget::class, CompilerEmbedder::class], $order);
    }

    public function testCompileOrdersATransitiveEmbedChainCorrectly(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([
            CompilerDeepEmbedOuter::class,
            CompilerDeepEmbedMiddle::class,
            CompilerEmbedTarget::class,
        ])));

        self::assertSame([
            CompilerEmbedTarget::class,
            CompilerDeepEmbedMiddle::class,
            CompilerDeepEmbedOuter::class,
        ], $order);
    }

    public function testCompileOrdersCollectionEmbedItemTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerCollectionEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame([CompilerEmbedTarget::class, CompilerCollectionEmbedder::class], $order);
    }

    public function testCompileLeavesASelfReferencingOwningReferenceAlone(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerNode::class])));

        self::assertSame([CompilerNode::class], $order);
    }

    public function testCompileWithMissingParentThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerChild::class]));
    }

    public function testCompileWithMissingEmbedTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerEmbedder::class]));
    }

    public function testCompileWithEmbedCycleThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerCycleA::class, CompilerCycleB::class]));
    }

    public function testCompileResolvesADependencyAlreadyRegisteredFromAnEarlierCompileCall(): void {
        $this->compiler->compile(self::blueprintsOf([CompilerRoot::class]));

        // CompilerRoot isn't in this second batch at all, but it's already
        // registered from the first compile() call, so this must not throw.
        $schemas = $this->compiler->compile(self::blueprintsOf([CompilerChild::class]));

        self::assertSame([CompilerChild::class], array_keys($schemas));
    }

    public function testCompileRegistersEveryCompiledSchemaWithTheRegistry(): void {
        $registry = new SchemaRegistry();
        $compiler = self::newCompiler($registry);

        $compiler->compile(self::blueprintsOf([CompilerEmbedTarget::class]));

        self::assertTrue($registry->has(CompilerEmbedTarget::class));
    }

    public function testCompileWritesFlattenedColumnsToTheValueStore(): void {
        $registry = new SchemaRegistry();
        $valueStore = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $valueStore->createTable();
        $compiler = new SchemaCompiler($registry, $valueStore);

        $compiler->compile(self::blueprintsOf([CompilerEmbedTarget::class]));

        self::assertSame(['value' => ''], $valueStore->load(CompilerEmbedTarget::class));
    }
}
