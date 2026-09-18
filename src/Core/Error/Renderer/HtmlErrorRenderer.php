<?php

namespace XyloIsCoding\CoconutCms\Core\Error\Renderer;

use Throwable;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\ErrorRenderer;

class HtmlErrorRenderer implements ErrorRenderer
{
    public function render(Throwable $error, ErrorContext $context, bool $debug): string
    {
        if ($debug) {
            return sprintf(
                '<h1>%s</h1><p>%s in %s:%d</p><p>Reference: %s</p><pre>%s</pre>',
                htmlspecialchars($error::class),
                htmlspecialchars($error->getMessage()),
                htmlspecialchars($error->getFile()),
                $error->getLine(),
                htmlspecialchars($context->referenceId),
                htmlspecialchars($error->getTraceAsString()),
            );
        }

        return sprintf(
            '<h1>Something went wrong</h1><p>Please try again later.</p><p>Reference: %s</p>',
            htmlspecialchars($context->referenceId),
        );
    }
}
