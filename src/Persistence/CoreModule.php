<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;

/** The CMS's own native types. Always booted, before every other module. */
final readonly class CoreModule implements Module
{
    public function name(): string {
        return 'coconut-cms';
    }

    public function classes(): array {
        return [Cms::class];
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }
}
