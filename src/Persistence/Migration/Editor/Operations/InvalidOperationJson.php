<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use RuntimeException;

/**
 * Thrown when a schema operation's JSON is wrong. Names the path at fault, so
 * what reads it can say where: the API reports it as invalid input.
 */
final class InvalidOperationJson extends RuntimeException
{
    /**
     * @param string $path Where it's wrong.
     * @param string $reason What's wrong there.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
    ) {
        parent::__construct($path === '' ? $reason : sprintf('"%s": %s', $path, $reason));
    }

    /** One reason, about $path. */
    public static function at(string $path, string $reason): self {
        return new self($path, $reason);
    }
}
