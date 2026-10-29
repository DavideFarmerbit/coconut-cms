<?php

namespace XyloIsCoding\CoconutCms\Admin;

/** How a module's admin code reaches the admin: built with it, or built by its author. */
enum AdminCodeKind: string
{
    /** A TypeScript entry, built together with the admin. */
    case Source = 'source';

    /** A folder its author built, copied as it is. */
    case Built = 'built';
}
