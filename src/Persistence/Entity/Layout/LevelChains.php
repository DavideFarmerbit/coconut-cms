<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * The levels an entity type's values are spread over, root first: one per
 * type in its chain. Each level is worked out once, then reused.
 */
final class LevelChains
{
    private readonly LayoutBuilder $layouts;

    /** @var array<string, LevelLayout> Keyed by identifier. */
    private array $levels = [];

    public function __construct(
        private readonly SchemaLookup $schemas,
    ) {
        $this->layouts = new LayoutBuilder($schemas);
    }

    /** @return list<LevelLayout> $identifier's levels, root first. */
    public function of(string $identifier): array {
        $chain = [];
        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            $chain[] = $this->levels[$current] ??= $this->layouts->levelOf($current);
        }

        return array_reverse($chain);
    }
}
