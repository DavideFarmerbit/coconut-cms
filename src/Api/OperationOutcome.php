<?php

namespace XyloIsCoding\CoconutCms\Api;

use XyloIsCoding\CoconutCms\Api\Error\ApiError;
use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What one call gave: its result, or its error. Api::run() never throws, it gives one of these. */
final readonly class OperationOutcome
{
    private function __construct(
        public ?OperationResult $result,
        public ?ApiError $error,
    ) {
    }

    public static function succeeded(OperationResult $result): self {
        return new self($result, null);
    }

    public static function failed(ApiError $error): self {
        return new self(null, $error);
    }

    /** The HTTP status it's answered with. */
    public function httpStatus(): int {
        return $this->error?->code->httpStatus() ?? 200;
    }
}
