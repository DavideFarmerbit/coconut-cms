<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

use Throwable;

interface ErrorRenderer
{
    public function render(Throwable $error, ErrorContext $context, bool $debug): string;
}
