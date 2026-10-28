<?php

namespace XyloIsCoding\CoconutCms\Tests\Api;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Api\Operation\OperationRegistry;

/** OperationRegistry: an operation is checked when it's registered, never on a call. */
final class OperationRegistryTest extends TestCase
{
    protected function setUp(): void {
        // The fixture file holds several classes: load it by its own.
        class_exists(ApiFixtures::class);
    }

    public function testAnOperationIsFoundByItsIdentifier(): void {
        $registry = new OperationRegistry();
        $registry->register(FixtureEcho::class);

        $definition = $registry->find('fixture:echo');
        self::assertSame(FixtureEcho::class, $definition->class);
        self::assertSame(FixtureInput::class, $definition->input);
        self::assertSame(FixtureResult::class, $definition->result);
        self::assertNull($registry->find('fixture:other'));
    }

    /** @return array<string, array{list<class-string>, string}> */
    public static function refusals(): array {
        return [
            'two with one identifier' => [[FixtureEcho::class, FixtureEchoAgain::class], 'Two operations are named "fixture:echo"'],
            'no attribute' => [[FixtureUnmarked::class], 'isn\'t marked #[Operation]'],
            'an untyped run()' => [[FixtureUntypedRun::class], 'needs a public run(SomeInput $input, OperationContext $context): SomeResult'],
            'a bad identifier' => [[FixtureBadName::class], 'is not a "namespace:key" identifier'],
        ];
    }

    /** @param list<class-string> $classes */
    #[DataProvider('refusals')]
    public function testAnOperationThatCantBeServedIsRefused(array $classes, string $message): void {
        $registry = new OperationRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        foreach ($classes as $class) {
            $registry->register($class);
        }
    }
}
