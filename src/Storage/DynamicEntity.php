<?php

namespace XyloIsCoding\CoconutCms\Storage;

/**
 * A hydrated instance of an editor-created prototype: no native class exists to
 * reflect on or instantiate, so its field values are held directly instead of as
 * promoted constructor properties. The editor-assembled counterpart to a native class
 * instance, read and written through the exact same Repository/ChangesetFlusher path,
 * see PrototypeRegistry::instantiate().
 */
final readonly class DynamicEntity
{
    /** @param array<string, mixed> $values field name => value, matching the identifier's PrototypeRegistry-resolved shape */
    public function __construct(
        public string $identifier,
        private array $values,
    ) {
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
}
