<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

/** What an actor does to a type's entities, each guarded by #[TypePermission]. */
enum TypeAction: string
{
    /** Loading one, or querying the type. */
    case Read = 'read';

    /** Saving a new one. */
    case Create = 'create';

    /** Saving a stored one with any value changed. */
    case Update = 'update';

    /** Deleting one, with everything it owns. */
    case Delete = 'delete';
}
