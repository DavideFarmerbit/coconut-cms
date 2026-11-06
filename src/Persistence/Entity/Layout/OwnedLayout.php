<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Where an OwningReference or an Owned Collection lives. Its items are
 * ordinary entities rows, found by owner and by owner_field.
 */
final readonly class OwnedLayout
{
    /**
     * @param string $ownerField What owned rows hold in owner_field: the
     *        field's id, joined with its Embeds' along the path.
     * @param string $flagColumn Its touched flag's column.
     * @param string|null $countColumn The column holding the item count, on
     *        the owner's own table. Null for a singular OwningReference.
     */
    public function __construct(
        public FieldPath $path,
        public string $ownerField,
        public string $flagColumn,
        public ?string $countColumn,
    ) {
    }
}
