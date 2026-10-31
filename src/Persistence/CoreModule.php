<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Api\Operations\Admin\ListAdminCode;
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
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateToDateTime;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
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
            ListAdminCode::class,
        ];
    }

    /** The built-in converters and initializers. */
    public function converters(): array {
        return [
            ConstantValue::class, CopyOfField::class,
            NumberToText::class, TextToNumber::class, TextToEnum::class, DateToDateTime::class,
        ];
    }

    /** None: the admin's own tools are part of the admin. */
    public function adminCode(): ?AdminCode {
        return null;
    }
}
