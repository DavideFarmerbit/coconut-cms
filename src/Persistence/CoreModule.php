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
use XyloIsCoding\CoconutCms\Api\Operations\Schema\GetSchema;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\ListConversions;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\ListValidators;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\ListValues;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\PreviewSchema;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\SaveSchema;
use XyloIsCoding\CoconutCms\Api\Operations\Types\GetType;
use XyloIsCoding\CoconutCms\Api\Operations\Types\ListTypes;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateTimeToDate;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateToDateTime;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\EnumToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\FirstItem;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\MapValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ToSingleItem;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxItems;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxLength;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MinItems;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\OnItems;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Pattern;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Range;

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
            ListTypes::class, GetType::class, GetSchema::class, PreviewSchema::class, SaveSchema::class, ListConversions::class, ListValidators::class, ListValues::class,
            GetEntity::class, QueryEntities::class, CreateEntity::class, UpdateEntity::class, DeleteEntity::class,
            ListAdminCode::class,
        ];
    }

    /** The built-in converters and initializers. */
    public function converters(): array {
        return [
            ConstantValue::class, CopyOfField::class,
            NumberToText::class, TextToNumber::class, TextToEnum::class, DateToDateTime::class,
            NumberToNumber::class, TextToText::class, EnumToText::class, MapValues::class, DateTimeToDate::class,
            ToSingleItem::class, FirstItem::class,
        ];
    }

    /** The built-in field rules. */
    public function validators(): array {
        return [MaxLength::class, Range::class, Pattern::class, MaxItems::class, MinItems::class, OnItems::class];
    }

    /** None: the admin's own tools are part of the admin. */
    public function adminCode(): ?AdminCode {
        return null;
    }
}
