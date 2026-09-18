<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

use Throwable;

class DefaultErrorLogger implements ErrorLogger
{
    public function log(Throwable $error): void
    {
        error_log(sprintf(
            '[%s] %s in %s:%d%s%s',
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            PHP_EOL,
            $error->getTraceAsString(),
        ));
    }
}
