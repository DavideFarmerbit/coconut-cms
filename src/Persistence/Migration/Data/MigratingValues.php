<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * The values rooted at one place an aggregate holds its type, for a
 * transformation or a level initializer to read and change. Paths are
 * relative to that place. Changes land in the aggregate as they're made.
 */
final readonly class MigratingValues implements Values
{
    /**
     * @param FieldPath|null $root Where these values are rooted: null for the
     *        aggregate's root itself.
     * @param string $join How $root joins to the fields under it.
     * @param list<string>|null $writable The fields that may be set, by name:
     *        null for any.
     */
    public function __construct(
        private AggregateValues $aggregate,
        private ?FieldPath $root,
        private string $join,
        private ?array $writable = null,
    ) {
    }

    public function has(string $path): bool {
        return $this->aggregate->has($this->absolute($path));
    }

    public function get(string $path): mixed {
        if (!$this->has($path)) {
            throw new InvalidArgumentException(sprintf('There is no value at "%s".', $path));
        }

        return $this->aggregate->get($this->absolute($path));
    }

    public function isDefault(string $path): bool {
        $this->get($path);

        return $this->aggregate->markOf($this->absolute($path))->isDefault();
    }

    public function paths(): array {
        if ($this->root === null) {
            return array_map('strval', array_keys($this->aggregate->values()));
        }

        return $this->aggregate->valuesUnder($this->root)->paths();
    }

    /**
     * Sets one value. A value with others under it is set one path at a
     * time, so $conversion carries nothing under it.
     */
    public function set(string $path, Conversion $conversion): void {
        $this->assertWritable($path);
        if ($conversion->under() !== []) {
            throw new InvalidArgumentException(sprintf('Set the values under "%s" one path at a time.', $path));
        }

        $absolute = $this->absolute($path);
        $this->aggregate->removeAt($absolute);
        if (!$conversion->isDefault()) {
            $this->aggregate->set($absolute, $conversion->value(), ValueMark::Converted);
        }
    }

    /** Whatever is at $path and under it goes, and follows its default. */
    public function remove(string $path): void {
        $this->assertWritable($path);
        $this->aggregate->removeAt($this->absolute($path));
    }

    /** Everything at $from and under it moves to $to, replacing whatever was there. */
    public function move(string $from, string $to): void {
        $this->assertWritable($from);
        $this->assertWritable($to);
        $this->aggregate->move($this->absolute($from), $this->absolute($to));
    }

    private function absolute(string $path): FieldPath {
        $relative = FieldPath::fromString($path);

        return $this->root === null ? $relative : $this->root->append($this->join, $relative);
    }

    private function assertWritable(string $path): void {
        if ($this->writable !== null && !in_array((string) FieldPath::fromString($path)->head(), $this->writable, true)) {
            throw new InvalidArgumentException(sprintf('"%s" may not be changed here: only %s may.', $path, implode(', ', $this->writable)));
        }
    }
}
