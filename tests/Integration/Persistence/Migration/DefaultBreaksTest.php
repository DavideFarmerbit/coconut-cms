<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\DefaultBreak;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxLength;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Pattern;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** The first version: a name with no rule. */
#[Entity('dft:car')]
final class DftCarV1
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::String)]
    public string $plate = 'AB123';
}

/** The second: the empty name is a placeholder the new rule refuses on purpose. */
#[Entity('dft:car')]
final class DftCarV2
{
    #[Scalar(ScalarType::String), FieldValidator(new Pattern('.'))]
    public string $name = '';

    #[Scalar(ScalarType::String), FieldValidator(new MaxLength(10))]
    public string $plate = 'AB123';
}

/** A type the second version doesn't change: never checked. */
#[Entity('dft:yard')]
final class DftYard
{
    #[Scalar(ScalarType::String), FieldValidator(new Pattern('.'))]
    public string $title = '';
}

/** A module of $classes. */
final readonly class DftModule implements Module
{
    /** @param list<class-string> $classes */
    public function __construct(private array $classes) {
    }

    public function name(): string {
        return 'dft';
    }

    public function classes(): array {
        return $this->classes;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function converters(): array {
        return [];
    }

    public function validators(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}

/** A pre-deploy check warns about a changed type's default breaking its rules, and never blocks for it. */
final class DefaultBreaksTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        Persistence::boot($this->connection, [new DftModule([DftCarV1::class, DftYard::class])])->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    public function testAChangedTypesDefaultBreakingARuleIsAWarning(): void {
        $report = Persistence::boot($this->connection, [new DftModule([DftCarV2::class, DftYard::class])])->check();

        self::assertEquals([new DefaultBreak('dft:car', 'name', 'must match .')], $report->defaultBreaks, 'The yard is unchanged; the plate fits.');
        self::assertFalse($report->isBlocked());
        self::assertStringContainsString('Warning: The default of "dft:car" at "name" breaks a rule: must match ..', $report->describe());
    }
}
