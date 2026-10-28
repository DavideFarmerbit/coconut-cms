<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/**
 * What a field's value must be for an entity to match: an operator and the
 * values it compares with, converted to stored form against the field when
 * the query runs. Built through Is, with values in PHP form; or through
 * ofStored(), with values already in stored form, as JSON gives them.
 */
final readonly class Condition
{
    /**
     * @param list<mixed> $values None for IsNull and IsNotNull, several for In, one otherwise.
     * @param bool $stored Whether $values are in stored form, not PHP form.
     */
    public function __construct(
        public Operator $operator,
        public array $values,
        public bool $stored = false,
    ) {
    }

    /**
     * $operator over $values in stored form: an enum's backing value, a date
     * as "Y-m-d", a value object's stored string, a Reference's target id.
     * Each is checked against the field when the query runs.
     * @param list<mixed> $values
     */
    public static function ofStored(Operator $operator, array $values): self {
        return new self($operator, array_values($values), true);
    }
}
