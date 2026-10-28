<?php

namespace XyloIsCoding\CoconutCms\Tests\Api;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;
use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Tests\Api\Nested\FixtureTag as Tag;

enum FixtureColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

enum FixtureSize: int
{
    case Small = 1;
    case Large = 2;
}

/** A nested input, named through an alias. */
final readonly class FixtureFilter implements OperationInput
{
    /** @param list<Tag> $tags */
    public function __construct(
        public string $path,
        public array $tags = [],
    ) {
    }
}

/** Every kind of parameter an input holds. */
final readonly class FixtureInput implements OperationInput
{
    /**
     * @param list<FixtureFilter> $filters
     * @param list<mixed> $values
     * @param int[] $counts
     */
    public function __construct(
        public string $name,
        public int $limit = 20,
        public float $ratio = 1.0,
        public bool $loud = false,
        public ?FixtureColor $color = null,
        public FixtureSize $size = FixtureSize::Small,
        public ?FixtureFilter $filter = null,
        public array $filters = [],
        public array $values = [],
        public array $counts = [],
    ) {
    }
}

/** A union isn't an input's. */
final readonly class FixtureUnionInput implements OperationInput
{
    public function __construct(
        public int|string $either,
    ) {
    }
}

/** An array without its items named isn't either. */
final readonly class FixtureBareArrayInput implements OperationInput
{
    public function __construct(
        public array $things,
    ) {
    }
}

/** Nor is a class that isn't an input. */
final readonly class FixtureObjectInput implements OperationInput
{
    public function __construct(
        public \DateTimeImmutable $when,
    ) {
    }
}

/** Nests one that isn't valid: caught when the outer one is read. */
final readonly class FixtureNestingInvalidInput implements OperationInput
{
    /** @param list<FixtureUnionInput> $items */
    public function __construct(
        public array $items,
    ) {
    }
}

/** A result with a map, a list, an enum and a nested object. */
final readonly class FixtureResult implements OperationResult
{
    /**
     * @param array<string, int> $counts
     * @param list<string> $names
     * @param array<string, mixed> $empty
     */
    public function __construct(
        public array $counts,
        public array $names,
        public array $empty,
        public FixtureColor $color,
        public ?FixtureResult $child = null,
    ) {
    }
}

#[Operation('fixture:echo')]
final readonly class FixtureEcho
{
    public function run(FixtureInput $input, OperationContext $context): FixtureResult {
        return new FixtureResult(['limit' => $input->limit], [$input->name], [], $input->color ?? FixtureColor::Red);
    }
}

#[Operation('fixture:echo')]
final readonly class FixtureEchoAgain
{
    public function run(FixtureInput $input, OperationContext $context): FixtureResult {
        return new FixtureResult([], [], [], FixtureColor::Red);
    }
}

final readonly class FixtureUnmarked
{
    public function run(FixtureInput $input, OperationContext $context): FixtureResult {
        return new FixtureResult([], [], [], FixtureColor::Red);
    }
}

#[Operation('fixture:untyped')]
final readonly class FixtureUntypedRun
{
    public function run(array $input, OperationContext $context): array {
        return [];
    }
}

#[Operation('not an identifier')]
final readonly class FixtureBadName
{
    public function run(FixtureInput $input, OperationContext $context): FixtureResult {
        return new FixtureResult([], [], [], FixtureColor::Red);
    }
}

/** Loads this file's classes. */
final class ApiFixtures
{
}
