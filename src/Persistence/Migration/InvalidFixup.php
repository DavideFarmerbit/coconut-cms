<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use LogicException;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * A fixup that can't apply to the schemas as they are when it runs: it names a
 * type or field that doesn't exist at that point, or one that already does.
 * Usually a typo, or a fixup placed in the wrong step.
 */
final class InvalidFixup extends LogicException
{
    /** The same problem, naming the step and the fixup it happened in. */
    public static function inStep(string $stepId, Fixup $fixup, self $previous): self {
        return new self(sprintf('Step "%s", %s: %s', $stepId, $fixup->describe(), $previous->getMessage()), 0, $previous);
    }

    /** The same problem, raised by a fixup a policy chose. */
    public static function fromPolicy(Fixup $fixup, Throwable $previous): self {
        return new self(sprintf('Policy fixup %s: %s', $fixup->describe(), $previous->getMessage()), 0, $previous);
    }
}
