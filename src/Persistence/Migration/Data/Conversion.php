<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * What a converter, initializer or transformation decides for one value: a
 * value, in stored form, or the default. Never a magic value standing for
 * "default".
 */
final readonly class Conversion
{
    /**
     * @param array<string, mixed> $under Values below it, keyed by path
     *        relative to it, in stored form.
     */
    private function __construct(
        private bool $isDefault,
        private mixed $value,
        private array $under,
    ) {
    }

    /**
     * $value, with $under below it: an Embed's or an owned object's fields, a
     * Collection's items. Compared with the new default once written: equal,
     * it stays untouched.
     * @param array<string, mixed> $under Keyed by path relative to the value:
     *        'weight', 'turbo.boost', '0.size'.
     */
    public static function of(mixed $value, array $under = []): self {
        return new self(false, $value, $under);
    }

    /** Untouched: the value follows the new default, whatever it is. */
    public static function default(): self {
        return new self(true, null, []);
    }

    public function isDefault(): bool {
        return $this->isDefault;
    }

    /** The value itself; null for the default. */
    public function value(): mixed {
        return $this->value;
    }

    /** @return array<string, mixed> The values below it, keyed by relative path. */
    public function under(): array {
        return $this->under;
    }
}
