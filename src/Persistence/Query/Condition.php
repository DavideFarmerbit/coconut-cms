<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/**
 * What a field's value must be for an entity to match: an operator and the
 * values it compares with, in PHP form, converted to stored form against the
 * field when the query runs. Built through Is.
 */
final readonly class Condition
{
    /** @param list<mixed> $values None for IsNull and IsNotNull, several for In, one otherwise. */
    public function __construct(
        public Operator $operator,
        public array $values,
    ) {
    }
}
