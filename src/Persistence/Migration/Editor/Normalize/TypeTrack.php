<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * One editor-created type followed through a save, whatever it's named along
 * the way: a type stored when the save started, or one the save creates.
 * What OperationNormalizer replays operations on.
 */
final class TypeTrack
{
    /** Whether the save deletes it. */
    public bool $deleted = false;

    /** @var array<string, FieldTrack> Its own fields now, keyed by their name now. */
    public array $fields = [];

    /** @var array<string, FieldTrack> Its fields the save removed, keyed by their name then: one added back takes up its values again. */
    public array $removed = [];

    /** @var list<FieldTrack> Every field it had or got, in the order they appeared. */
    public array $all = [];

    /**
     * @param string|null $original Its identifier when the save started; null for one the save creates.
     * @param TypeTrack|string|null $parent Its parent: an editor-created type, a native one's identifier, or none.
     */
    public function __construct(
        public readonly ?string $original,
        public string $name,
        public TypeTrack|string|null $parent,
    ) {
    }

    /** Whether the save creates it. */
    public function isNew(): bool {
        return $this->original === null;
    }

    /** Adds $field as one it starts with, or the save adds to it. */
    public function add(FieldTrack $field): void {
        $this->fields[$field->name] = $field;
        $this->all[] = $field;
        $field->owner = $this;
        $field->firstOwner = $this;
    }

    /** Takes $field over from the type declaring it now: a move up or down the chain. */
    public function takeOver(FieldTrack $field): void {
        unset($field->owner->fields[$field->name]);
        $this->fields[$field->name] = $field;
        $field->owner = $this;
    }

    /** A field it had when the save started, as stored. */
    public function addOriginal(FieldDescriptor $descriptor): void {
        $this->add(new FieldTrack($descriptor->name(), $descriptor, $descriptor->name(), $descriptor));
    }

    /** Its parent's identifier now: an editor-created parent under its name now. */
    public function parentName(): ?string {
        return $this->parent instanceof self ? $this->parent->name : $this->parent;
    }
}
