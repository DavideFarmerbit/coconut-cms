<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * One field followed through a save, whatever it's named along the way: a
 * field the type had when the save started, or one the save adds. What
 * OperationNormalizer replays operations on.
 */
final class FieldTrack
{
    /** Whether the save removed it, and didn't add it back. */
    public bool $removed = false;

    /**
     * What changed what it holds, in order, each naming the field as it was
     * then: retypes, made required, and an existing field added back.
     * @var list<RetypeField|MakeRequired|AddField>
     */
    public array $changes = [];

    /**
     * @param string|null $original Its name when the save started; null for one the save adds.
     * @param FieldDescriptor|null $originalDescriptor What it held when the save started.
     * @param DefaultValue|null $default The default an added field pins, its last one.
     * @param AddField|null $addedBy The operation adding it, for one the save adds.
     */
    public function __construct(
        public readonly ?string $original,
        public readonly ?FieldDescriptor $originalDescriptor,
        public string $name,
        public FieldDescriptor $descriptor,
        public ?DefaultValue $default = null,
        public readonly ?FieldInitializer $initializer = null,
        public readonly ?AddField $addedBy = null,
    ) {
    }

    /** Whether the save adds it. */
    public function isNew(): bool {
        return $this->original === null;
    }

    /** Whether it ends up holding what it held when the save started. */
    public function holdsWhatItHeld(): bool {
        return $this->originalDescriptor !== null && $this->originalDescriptor->renamed($this->name) == $this->descriptor;
    }
}
