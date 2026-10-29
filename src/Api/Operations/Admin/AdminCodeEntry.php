<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Admin;

use XyloIsCoding\CoconutCms\Admin\AdminCodeKind;

/** One module's admin code, as the admin's build needs it. */
final readonly class AdminCodeEntry
{
    /**
     * @param string $module The module's name: what the admin knows its plugin by.
     * @param string $path Its entry file, or its built folder: an absolute path.
     */
    public function __construct(
        public string $module,
        public AdminCodeKind $kind,
        public string $path,
    ) {
    }
}
