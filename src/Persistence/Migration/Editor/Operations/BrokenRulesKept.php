<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken\BrokenRule;

/**
 * How an operation setting a list of rules takes a broken one: only where
 * the same rule is already, so a save keeps a broken rule or removes it,
 * and never adds one.
 */
final class BrokenRulesKept
{
    /**
     * Throws unless every broken rule in $rules is in $current, by its
     * stored entry.
     * @param list<object> $rules The list the operation sets.
     * @param array<int, object> $current The list there now.
     * @throws InvalidOperation
     */
    public static function assertIn(array $rules, array $current): void {
        $stored = [];
        foreach ($current as $rule) {
            if ($rule instanceof BrokenRule) {
                $stored[] = $rule->entry();
            }
        }

        foreach ($rules as $index => $rule) {
            if ($rule instanceof BrokenRule && !in_array($rule->entry(), $stored)) {
                throw new InvalidOperation(sprintf('Rule %d: %s', $index, $rule->reason()));
            }
        }
    }
}
