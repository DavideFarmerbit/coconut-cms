<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use XyloIsCoding\CoconutCms\Api\Operations\Auth\GetMe;
use XyloIsCoding\CoconutCms\Api\Operations\Auth\SignIn;
use XyloIsCoding\CoconutCms\Api\Operations\Auth\SignOut;
use XyloIsCoding\CoconutCms\Api\Operations\Entities\CreateEntity;
use XyloIsCoding\CoconutCms\Api\Operations\Entities\DeleteEntity;
use XyloIsCoding\CoconutCms\Api\Operations\Entities\GetEntity;
use XyloIsCoding\CoconutCms\Api\Operations\Entities\UpdateEntity;
use XyloIsCoding\CoconutCms\Api\Operations\Entities\QueryEntities;
use XyloIsCoding\CoconutCms\Api\Operations\Types\GetType;
use XyloIsCoding\CoconutCms\Api\Operations\Types\ListTypes;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;

/** The CMS's own native types and built-in operations. Always booted, before every other module. */
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

    public function operations(): array {
        return [
            SignIn::class, SignOut::class, GetMe::class,
            ListTypes::class, GetType::class,
            GetEntity::class, QueryEntities::class, CreateEntity::class, UpdateEntity::class, DeleteEntity::class,
        ];
    }
}
