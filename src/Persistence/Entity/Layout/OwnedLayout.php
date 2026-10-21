<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Where an OwningReference or an Owned Collection lives. Its items are
 * ordinary entities rows, found by owner and by owner_field, which holds
 * $path's string form.
 */
final readonly class OwnedLayout
{
    /**
     * @param string|null $countColumn The column holding the item count, on
     *        the owner's own table. Null for a singular OwningReference.
     */
    public function __construct(
        public FieldPath $path,
        public ?string $countColumn,
    ) {
    }

    /** What owned rows hold in owner_field. */
    public function ownerField(): string {
        return (string) $this->path;
    }
}
