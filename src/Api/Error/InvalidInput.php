<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

use RuntimeException;

/**
 * Thrown when an operation's input is wrong: by InputReader before the
 * operation runs, or by the operation itself for what only it can check.
 * Holds a message per path, "where.0.path", so a form can show each.
 */
final class InvalidInput extends RuntimeException
{
    /** @param array<string, string> $errors A message per path; "" for the input as a whole. */
    public function __construct(
        public readonly array $errors,
    ) {
        $lines = [];
        foreach ($errors as $path => $message) {
            $lines[] = $path === '' ? $message : sprintf('"%s": %s', $path, $message);
        }

        parent::__construct(sprintf('The input is invalid: %s', implode('; ', $lines)));
    }

    /** One message, about $path. */
    public static function at(string $path, string $message): self {
        return new self([$path => $message]);
    }
}
