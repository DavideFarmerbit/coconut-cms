<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Record;

/**
 * One entity's values as plain data, as PersistenceSession::read() gives
 * them: native or editor-created alike, no PHP class built. Also what an
 * Embed or an owned object inside it reads as. Only the fields its reader
 * may read are in it.
 */
final readonly class EntityRecord
{
    /**
     * @param string $type Its concrete type's identifier; an Embed's declared target.
     * @param string|null $id Its id; null for an Embed, which has none.
     * @param array<string, mixed> $values Each readable field's value, by
     *        name: a scalar or a value object in stored form, or null; an
     *        Embed or an owned object as another EntityRecord, or null for an
     *        empty slot; a Reference as a ReferenceRecord, or null; a
     *        Collection as a list of its items in the same forms.
     */
    public function __construct(
        public string $type,
        public ?string $id,
        public array $values,
    ) {
    }
}
