<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Admin;

use XyloIsCoding\CoconutCms\Api\Error\Forbidden;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Api\Operations\Auth\NoInput;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;

/**
 * Every module's admin code, what `npm run build:admin` builds or copies
 * into the admin: `vendor/bin/coconut coconut-cms:admin.code --output=json`.
 * For the command line only: it names files on the server.
 */
#[Operation('coconut-cms:admin.code')]
final readonly class ListAdminCode
{
    public function run(NoInput $input, OperationContext $context): AdminCodeList {
        if (!$context->actor instanceof SystemActor) {
            throw new Forbidden('The admin\'s code is listed on the command line only.');
        }

        $entries = [];
        foreach ($context->persistence->modules() as $module) {
            $code = $module->adminCode();
            if ($code !== null) {
                $entries[] = new AdminCodeEntry($module->name(), $code->kind, $code->path);
            }
        }

        return new AdminCodeList($entries);
    }
}
