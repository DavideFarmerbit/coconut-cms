<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

use InvalidArgumentException;

/** A value written as is: `null`, `true`, `"text"`, `12.50`, `@2024-01-31`. */
final readonly class Literal implements Expr
{
    /** @param bool|string|null $value A number, a date and a datetime as text. */
    public function __construct(
        public LiteralKind $kind,
        public bool|string|null $value,
    ) {
        $valid = match ($kind) {
            LiteralKind::Null => $value === null,
            LiteralKind::Bool => is_bool($value),
            LiteralKind::Text => is_string($value),
            LiteralKind::Number => is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1,
            LiteralKind::Date => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            LiteralKind::DateTime => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1,
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Not a %s literal: %s.', $kind->value, var_export($value, true)));
        }
    }

    public static function null(): self {
        return new self(LiteralKind::Null, null);
    }

    public static function text(string $text): self {
        return new self(LiteralKind::Text, $text);
    }

    public static function number(string $digits): self {
        return new self(LiteralKind::Number, $digits);
    }
}
