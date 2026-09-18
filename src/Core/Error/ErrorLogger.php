<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

use Throwable;

interface ErrorLogger
{
    public function log(Throwable $error, ErrorContext $context): void;
}
