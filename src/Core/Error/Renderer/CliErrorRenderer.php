<?php

namespace XyloIsCoding\CoconutCms\Core\Error\Renderer;

use Throwable;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\ErrorRenderer;

class CliErrorRenderer implements ErrorRenderer
{
    public function render(Throwable $error, ErrorContext $context, bool $debug): string
    {
        if ($debug) {
            return sprintf(
                "%s: %s\nin %s:%d\nReference: %s\n\n%s\n",
                $error::class,
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $context->referenceId,
                $error->getTraceAsString(),
            );
        }

        return sprintf("Something went wrong. Reference: %s\n", $context->referenceId);
    }
}
