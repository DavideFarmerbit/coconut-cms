<?php

namespace XyloIsCoding\CoconutCms\Admin;

/**
 * A module's admin code, as Module::adminCode() declares it. `npm run
 * build:admin` reads every module's, through coconut-cms:admin.code:
 *
 * - source(): a TypeScript entry, built together with the admin into one
 *   build, sharing its code. What a project's own module, or a plugin
 *   leaving its build to the project, declares.
 * - built(): a folder its author built with any toolchain, holding an
 *   `admin-plugin.json` naming its entry script and stylesheets. Copied into
 *   the admin's build folder as it is; its import of "@coconut-cms/admin"
 *   reaches the running admin through the page's import map.
 *
 * Either entry's default export is a class implementing AdminPlugin.
 */
final readonly class AdminCode
{
    private function __construct(
        public AdminCodeKind $kind,
        /** The entry file, or the built folder: an absolute path. */
        public string $path,
    ) {
    }

    /** The TypeScript entry at `$file`, built with the admin. */
    public static function source(string $file): self {
        return new self(AdminCodeKind::Source, $file);
    }

    /** The author's build in `$folder`, with its `admin-plugin.json`. */
    public static function built(string $folder): self {
        return new self(AdminCodeKind::Built, $folder);
    }
}
