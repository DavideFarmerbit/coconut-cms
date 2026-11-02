<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** schema.preview's input: what schema.save takes, and how many entries to sample. */
final readonly class SchemaPreviewInput implements OperationInput
{
    /** The most entries a preview samples. */
    public const MAX_SAMPLES = 20;

    /**
     * @param int $revision The schema revision types.list gave when the admin started.
     * @param list<mixed> $operations Each as OperationForm reads it, in the order the admin made them.
     * @param bool $acceptBreaks Whether the admin saw the breaks a preview listed, and accepts them.
     * @param int $samples How many entries that change to show, from 0 to MAX_SAMPLES.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public bool $acceptBreaks = false,
        public int $samples = 3,
    ) {
        if ($samples < 0 || $samples > self::MAX_SAMPLES) {
            throw InvalidInput::at('samples', sprintf('From 0 to %d.', self::MAX_SAMPLES));
        }
    }

    /** The same, as schema.save takes it. */
    public function save(): SchemaSaveInput {
        return new SchemaSaveInput($this->revision, $this->operations, $this->acceptBreaks);
    }
}
