<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EditorDefaultResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity]
final class EditorDefaultResolverLowPoweredEngine
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower = 50;
}

final class EditorDefaultResolverTest extends TestCase
{
    public function testResolveFallsThroughToNativeParentWhenNoOverrideExists(): void {
        $resolver = new EditorDefaultResolver(
            overrides: [],
            parents: ['QuietEngine' => EntityRegistrarEngineBase::class],
        );

        self::assertSame(100, $resolver->resolve('QuietEngine', 'horsepower'));
    }

    public function testResolveReturnsTheExplicitOverrideWhenOneExists(): void {
        $resolver = new EditorDefaultResolver(
            overrides: ['SpecialEngine' => ['horsepower' => 999]],
            parents: ['SpecialEngine' => EntityRegistrarEngineBase::class],
        );

        self::assertSame(999, $resolver->resolve('SpecialEngine', 'horsepower'));
    }

    public function testResolveFallsThroughAnotherEditorScopeToItsOwnOverride(): void {
        $resolver = new EditorDefaultResolver(
            overrides: ['SpecialEngine' => ['horsepower' => 999]],
            parents: [
                'SpecialEngine' => EntityRegistrarEngineBase::class,
                'UltraSpecialEngine' => 'SpecialEngine',
            ],
        );

        self::assertSame(999, $resolver->resolve('UltraSpecialEngine', 'horsepower'));
    }

    public function testResolveLetsAnEditorScopeOverrideItsEditorParentsOverride(): void {
        $resolver = new EditorDefaultResolver(
            overrides: [
                'SpecialEngine' => ['horsepower' => 999],
                'UltraSpecialEngine' => ['horsepower' => 1],
            ],
            parents: [
                'SpecialEngine' => EntityRegistrarEngineBase::class,
                'UltraSpecialEngine' => 'SpecialEngine',
            ],
        );

        self::assertSame(1, $resolver->resolve('UltraSpecialEngine', 'horsepower'));
    }

    public function testResolveWithNoOverrideAndNoParentThrows(): void {
        $resolver = new EditorDefaultResolver(overrides: [], parents: []);

        $this->expectException(InvalidArgumentException::class);

        $resolver->resolve('Unknown', 'horsepower');
    }

    public function testResolveReadsEachNativeParentsOwnCurrentValueIndependently(): void {
        $resolver = new EditorDefaultResolver(
            overrides: [],
            parents: [
                'QuietEngine' => EntityRegistrarEngineBase::class,
                'EconomyEngine' => EditorDefaultResolverLowPoweredEngine::class,
            ],
        );

        self::assertSame(100, $resolver->resolve('QuietEngine', 'horsepower'));
        self::assertSame(50, $resolver->resolve('EconomyEngine', 'horsepower'));
    }

    public function testResolveKeepsAnExplicitOverrideRegardlessOfWhatItsNativeParentReports(): void {
        $overriddenToMatchOldDefault = new EditorDefaultResolver(
            overrides: ['SpecialEngine' => ['horsepower' => 100]],
            parents: ['SpecialEngine' => EntityRegistrarEngineBase::class],
        );
        $noOverride = new EditorDefaultResolver(
            overrides: [],
            parents: ['QuietEngine' => EntityRegistrarEngineBase::class],
        );

        // Both read the same native parent's current value (100) right now, one via
        // an explicit override that happens to match it, one via plain fallback.
        self::assertSame(100, $overriddenToMatchOldDefault->resolve('SpecialEngine', 'horsepower'));
        self::assertSame(100, $noOverride->resolve('QuietEngine', 'horsepower'));

        // If EntityRegistrarEngineBase's own declared default changed from 100 to
        // something else, the no-override scope would follow it automatically on
        // its next resolve() call, with nothing to update. The explicit-override
        // scope would keep returning 100 regardless, because its row never
        // encoded "defer to parent" in the first place, only "I am 100".
    }

    public function testResolveSupportsAnOwningFieldSiteOverridingWhatItOwnsRatherThanItsOwnType(): void {
        // "AudiCar.engine" is not a type extending EntityRegistrarEngineBase. It's
        // one specific owning field falling back to the type it targets, the same
        // way a type scope falls back to its own parent type: same mechanism,
        // different reason the parent link exists.
        $resolver = new EditorDefaultResolver(
            overrides: ['AudiCar.engine' => ['horsepower' => 250]],
            parents: ['AudiCar.engine' => EntityRegistrarEngineBase::class],
        );

        self::assertSame(250, $resolver->resolve('AudiCar.engine', 'horsepower'));
    }

    public function testResolveFallsThroughAnOwningFieldSiteToItsTargetTypesNativeDefault(): void {
        $resolver = new EditorDefaultResolver(
            overrides: [],
            parents: ['AudiCar.engine' => EntityRegistrarEngineBase::class],
        );

        self::assertSame(100, $resolver->resolve('AudiCar.engine', 'horsepower'));
    }

    public function testResolveKeepsAnOwningFieldSiteIndependentFromAnUnrelatedTypeOverride(): void {
        // SpecialEngine overrides horsepower for its own type. AudiCar.engine
        // targets EntityRegistrarEngineBase directly, not SpecialEngine, so
        // SpecialEngine's override must have no bearing on it.
        $resolver = new EditorDefaultResolver(
            overrides: ['SpecialEngine' => ['horsepower' => 999]],
            parents: [
                'SpecialEngine' => EntityRegistrarEngineBase::class,
                'AudiCar.engine' => EntityRegistrarEngineBase::class,
            ],
        );

        self::assertSame(100, $resolver->resolve('AudiCar.engine', 'horsepower'));
    }
}
