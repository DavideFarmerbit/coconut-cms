<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use InvalidArgumentException;

/** Values as they were when read: what a converter or field initializer receives. Read-only. */
final readonly class ValuesSnapshot implements Values
{
    /**
     * @param array<string, mixed> $values Keyed by relative path.
     * @param array<string, bool> $defaults Keyed by relative path: whether
     *        each value follows its old default.
     */
    public function __construct(
        private array $values,
        private array $defaults,
    ) {
    }

    public function has(string $path): bool {
        return array_key_exists($path, $this->values);
    }

    public function get(string $path): mixed {
        if (!$this->has($path)) {
            throw new InvalidArgumentException(sprintf('There is no value at "%s".', $path));
        }

        return $this->values[$path];
    }

    public function isDefault(string $path): bool {
        $this->get($path);

        return $this->defaults[$path];
    }

    public function paths(): array {
        return array_map('strval', array_keys($this->values));
    }
}
