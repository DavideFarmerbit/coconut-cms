<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;

/** One break a schema save causes on another editor-created type, and what the policy does with it. */
final readonly class SchemaBreak
{
    /**
     * @param string $type The type it breaks.
     * @param string|null $setAside The fixup setting it aside, described; null when the policy blocks it.
     */
    public function __construct(
        public string $type,
        public string $description,
        public ?string $setAside,
    ) {
    }

    public static function of(BreakResolution $resolution): self {
        return new self($resolution->break->identifier(), $resolution->break->describe(), $resolution->setAside?->describe());
    }
}
