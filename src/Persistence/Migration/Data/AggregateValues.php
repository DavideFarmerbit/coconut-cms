<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * One aggregate's values while they move: every value in effective stored
 * form, each with its mark, keyed by path from the root, and the id of every
 * owned object, keyed by its slot's path. Fixups change it in place; renames
 * and moves carry marks and ids along.
 *
 * It can also be a stored type's default instance (ofDefaults()), moving what
 * the type sets itself: every value moves, so each fixup finds the shape it
 * expects, but only what the type set, wherever it moved and however it was
 * converted, is kept (ownValues()).
 */
final class AggregateValues
{
    /**
     * For a type's default instance, every path the type sets itself; null
     * for an entity. A path stays its own through moves, conversions and
     * replacements; a removal ends it.
     * @var array<string, true>|null
     */
    private ?array $own = null;

    /**
     * @param string $identifier The root's concrete identifier.
     * @param array<string, mixed> $values Keyed by path.
     * @param array<string, ValueMark> $marks Keyed by path, one per value.
     * @param array<string, string> $ownedIds Keyed by the owned object's slot path.
     */
    public function __construct(
        public string $identifier,
        private array $values,
        private array $marks,
        private array $ownedIds,
    ) {
    }

    /**
     * Type $identifier's default instance, to carry what it sets itself
     * through a migration: what it sets is touched, what it inherits isn't.
     * @param array<string, mixed> $values Every default, inherited ones included, keyed by path.
     * @param list<string> $own The paths the type sets itself.
     */
    public static function ofDefaults(string $identifier, array $values, array $own): self {
        $own = array_fill_keys($own, true);
        $marks = [];
        foreach (array_keys($values) as $path) {
            $marks[$path] = isset($own[$path]) ? ValueMark::Touched : ValueMark::Untouched;
        }

        $defaults = new self($identifier, $values, $marks, []);
        $defaults->own = $own;

        return $defaults;
    }

    /**
     * What a default instance's type sets itself now: every value at a path
     * still its own. A value derived into a path it didn't set isn't.
     * @return array<string, mixed> Keyed by path.
     */
    public function ownValues(): array {
        return array_intersect_key($this->values, $this->own ?? []);
    }

    public function has(FieldPath $path): bool {
        return array_key_exists((string) $path, $this->values);
    }

    /** The value at $path, null when there's none. */
    public function get(FieldPath $path): mixed {
        return $this->values[(string) $path] ?? null;
    }

    /** $path's mark: untouched when nothing is there. */
    public function markOf(FieldPath $path): ValueMark {
        return $this->marks[(string) $path] ?? ValueMark::Untouched;
    }

    public function set(FieldPath $path, mixed $value, ValueMark $mark): void {
        $this->values[(string) $path] = $value;
        $this->marks[(string) $path] = $mark;
    }

    /**
     * Whatever is at $path and under it goes: from then on, it follows its
     * default. For a default instance, its type no longer sets it.
     */
    public function removeAt(FieldPath $path): void {
        foreach (array_keys($this->values) as $key) {
            if (self::isAtOrUnder((string) $key, (string) $path)) {
                unset($this->values[$key], $this->marks[$key], $this->own[$key]);
            }
        }

        foreach (array_keys($this->ownedIds) as $key) {
            if (self::isAtOrUnder((string) $key, (string) $path)) {
                unset($this->ownedIds[$key]);
            }
        }
    }

    /**
     * Everything at $from and under it moves to $to, marks and owned ids
     * included, replacing whatever was at $to.
     */
    public function move(FieldPath $from, FieldPath $to): void {
        if ((string) $from === (string) $to) {
            return;
        }

        $this->removeAt($to);
        $this->values = self::rekeyed($this->values, (string) $from, (string) $to);
        $this->marks = self::rekeyed($this->marks, (string) $from, (string) $to);
        $this->ownedIds = self::rekeyed($this->ownedIds, (string) $from, (string) $to);
        if ($this->own !== null) {
            $this->own = self::rekeyed($this->own, (string) $from, (string) $to);
        }
    }

    /**
     * The values rooted at $root, as an initializer sees them: every value for
     * the aggregate's root itself, when $root is null.
     */
    public function valuesAt(?FieldPath $root): Values {
        if ($root !== null) {
            return $this->valuesUnder($root);
        }

        return new ValuesSnapshot($this->values, array_map(static fn (ValueMark $mark): bool => $mark->isDefault(), $this->marks));
    }

    /**
     * The values strictly under $path, keyed by path relative to it, read as
     * a converter or initializer sees them.
     */
    public function valuesUnder(FieldPath $path): Values {
        $values = [];
        $defaults = [];
        foreach ($this->values as $key => $value) {
            $relative = self::relativeTo((string) $key, (string) $path);
            if ($relative !== null) {
                $values[$relative] = $value;
                $defaults[$relative] = $this->marks[$key]->isDefault();
            }
        }

        return new ValuesSnapshot($values, $defaults);
    }

    /**
     * What a converter, initializer or transformation decided for the value
     * of $field at $path, replacing whatever was there.
     */
    public function write(FieldPath $path, FieldDescriptor $field, Conversion $conversion): void {
        // For a default instance: a value at a path that was there keeps
        // whether its type set it; a value at a new path is its own when the
        // type set anything of the field. A field it inherits, or one new to
        // it, never becomes its own.
        $before = $this->ownAtOrUnder((string) $path);
        $existed = $this->valuesAtOrUnder((string) $path);
        $this->removeAt($path);
        if ($conversion->isDefault()) {
            return;
        }

        $written = [];
        // An Embed has no value of its own, only its fields.
        if ($field->kind() !== FieldKind::Embed) {
            $this->set($path, $conversion->value(), ValueMark::Converted);
            $written[] = (string) $path;
        }

        $join = $field->kind() === FieldKind::Embed ? FieldPath::EMBED : FieldPath::TRAVERSE;
        foreach ($conversion->under() as $relative => $value) {
            $under = $path->append($join, FieldPath::fromString((string) $relative));
            $this->set($under, $value, ValueMark::Converted);
            $written[] = (string) $under;
        }

        if ($this->own !== null) {
            foreach ($written as $key) {
                if (isset($existed[$key]) ? isset($before[$key]) : $before !== []) {
                    $this->own[$key] = true;
                }
            }
        }
    }

    /**
     * Replaces whatever is at $path and under it with $value: for a default
     * instance, the path stays its type's own if it was.
     */
    public function replaceAt(FieldPath $path, mixed $value, ValueMark $mark): void {
        $wasOwn = isset($this->own[(string) $path]);
        $this->removeAt($path);
        $this->set($path, $value, $mark);
        if ($wasOwn) {
            $this->own[(string) $path] = true;
        }
    }

    /**
     * Every path at or under $path holding a value.
     * @return array<string, true>
     */
    private function valuesAtOrUnder(string $path): array {
        $found = [];
        foreach (array_keys($this->values) as $key) {
            if (self::isAtOrUnder((string) $key, $path)) {
                $found[(string) $key] = true;
            }
        }

        return $found;
    }

    /**
     * For a default instance, the paths at or under $path its type sets itself.
     * @return array<string, true>
     */
    private function ownAtOrUnder(string $path): array {
        return array_filter($this->own ?? [], static fn (string $key): bool => self::isAtOrUnder($key, $path), ARRAY_FILTER_USE_KEY);
    }

    /** The id of the owned object in the slot at $path, null when it has none yet. */
    public function ownedIdAt(FieldPath $path): ?string {
        return $this->ownedIds[(string) $path] ?? null;
    }

    /** @return array<string, mixed> Every value, keyed by path. */
    public function values(): array {
        return $this->values;
    }

    /** @return array<string, ValueMark> Every value's mark, keyed by path. */
    public function marks(): array {
        return $this->marks;
    }

    /** @return array<string, string> Every owned object's id, keyed by its slot's path. */
    public function ownedIds(): array {
        return $this->ownedIds;
    }

    private static function isAtOrUnder(string $key, string $path): bool {
        return $key === $path || self::relativeTo($key, $path) !== null;
    }

    /** $key relative to $path when it lies strictly under it, joined by either separator; null otherwise. */
    private static function relativeTo(string $key, string $path): ?string {
        foreach ([FieldPath::EMBED, FieldPath::TRAVERSE] as $join) {
            if (str_starts_with($key, $path . $join)) {
                return substr($key, strlen($path) + 1);
            }
        }

        return null;
    }

    /**
     * @template T
     * @param array<string, T> $entries
     * @return array<string, T> $entries with every key at or under $from moved under $to.
     */
    private static function rekeyed(array $entries, string $from, string $to): array {
        $rekeyed = [];
        foreach ($entries as $key => $entry) {
            $key = (string) $key;
            if (self::isAtOrUnder($key, $from)) {
                // Keeps whatever joins the rest on: "price:amount" moves to "cost:amount".
                $key = $to . substr($key, strlen($from));
            }

            $rekeyed[$key] = $entry;
        }

        return $rekeyed;
    }
}
