<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;

/**
 * A group of native entity classes with the migration steps that brought
 * them where they are: the CMS's own, a plugin's, a project's. Booted in
 * order, core first, and migrated in that order. Each step is recorded under
 * its module's name, "app:rename-price", so two modules' ids never collide.
 */
interface Module
{
    /** What its step ids are recorded under: lowercase letters, digits and "-". */
    public function name(): string;

    /**
     * Every native entity class it declares. The value objects, enums and
     * validator rules they use are found through them.
     * @return list<class-string>
     */
    public function classes(): array;

    /** Every step it ever declared, applied or not, in order. */
    public function steps(): MigrationSteps;

    /**
     * Every API operation it declares: classes marked #[Operation], served
     * by the Api over HTTP and the command line.
     * @return list<class-string>
     */
    public function operations(): array;

    /**
     * Every converter and initializer it offers editor saves: FieldConverter
     * classes marked #[Converter], FieldInitializer classes marked
     * #[Initializer]. One a migration step uses only in code needs no entry.
     * @return list<class-string>
     */
    public function converters(): array;

    /** Its admin code, which `npm run build:admin` builds or copies into the admin; null for none. */
    public function adminCode(): ?AdminCode;
}
