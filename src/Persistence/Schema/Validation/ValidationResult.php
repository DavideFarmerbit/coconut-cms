<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * The outcome of one FieldValidatorRule or PrototypeValidatorRule check: errors
 * on what it checked, and errors inside it, under a Collection item's index or
 * an Embed's or owned object's field. The validator places each under its
 * full path: `atItem(1)` on `tags` lands on "tags.1", `atField('weight')` on
 * the Embed `chassis` on "chassis:weight".
 */
final readonly class ValidationResult
{
    /**
     * @param list<string> $errors Errors on what was checked itself.
     * @param list<array{FieldName|int, self}> $inside Results inside it, each under its segment; never valid ones.
     */
    private function __construct(
        private array $errors,
        private array $inside,
    ) {
    }

    public static function valid(): self {
        return new self([], []);
    }

    /**
     * @param string $error The reason validation failed.
     */
    public static function invalid(string $error): self {
        return new self([$error], []);
    }

    /** Every error of $results, in one. */
    public static function combined(self ...$results): self {
        $errors = [];
        $inside = [];
        foreach ($results as $result) {
            $errors = [...$errors, ...$result->errors];
            $inside = [...$inside, ...$result->inside];
        }

        return new self($errors, $inside);
    }

    /** This result, as found on a Collection's item at $index. */
    public function atItem(int $index): self {
        return $this->isValid() ? $this : new self([], [[$index, $this]]);
    }

    /** This result, as found on the field $field inside what was checked: an Embed's or an owned object's. */
    public function atField(string $field): self {
        return $this->isValid() ? $this : new self([], [[FieldName::of($field), $this]]);
    }

    public function isValid(): bool {
        return $this->errors === [] && $this->inside === [];
    }

    /** @return list<string> The errors on what was checked itself. */
    public function errors(): array {
        return $this->errors;
    }

    /**
     * The first error on what was checked itself: what a rule with one
     * message reports. Throws when there's none.
     */
    public function error(): string {
        return $this->errors[0] ?? throw new LogicException('This ValidationResult has no error of its own.');
    }

    /** @return list<array{FieldName|int, self}> The results inside what was checked, each under its segment. */
    public function inside(): array {
        return $this->inside;
    }
}
