<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use LogicException;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

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

    /**
     * Throws unless $field matches $signature: $what, a converter or
     * initializer, $role the field ("takes", "gives", "fills"), named as
     * field $name of type $type.
     */
    public static function unlessMatches(FieldSignature $signature, FieldDescriptor $field, string $what, string $role, string $type, string $name): void {
        if (!$signature->matches($field)) {
            throw new self(sprintf('%s %s %s, and field "%s" of "%s" is not one.', $what, $role, $signature->describe(), $name, $type));
        }
    }

    /** The same problem, raised by a fixup a policy chose. */
    public static function fromPolicy(Fixup $fixup, Throwable $previous): self {
        return new self(sprintf('Policy fixup %s: %s', $fixup->describe(), $previous->getMessage()), 0, $previous);
    }
}
