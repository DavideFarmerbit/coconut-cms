<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
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
     * Every field set aside so far, captured: by the entity holding it ("" for
     * the root, an owned object's id otherwise), the record it belongs to, and
     * the site inside the entity where its type sits ("" for the entity
     * itself, an Embed's path otherwise).
     * @var array<string, array<string, array<string, array{values: array<string, mixed>, touched: list<string>, ownedIds: array<string, string>}>>>
     */
    private array $setAside = [];

    /** Whether a ReplaceType made the root another type: a default instance's then belongs to nobody. */
    private bool $replaced = false;

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
     * The root made a $with by a ReplaceType: an entity becomes one, keeping
     * its id. A default instance's values then belong to no type: the
     * replacing type keeps its own defaults.
     */
    public function replaceRoot(string $with): void {
        $this->identifier = $with;
        $this->replaced = true;
    }

    /** Whether a ReplaceType made the root another type. */
    public function wasReplaced(): bool {
        return $this->replaced;
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
        $this->moveAll([[$from, $to]]);
    }

    /**
     * Everything at each move's first path and under it moves to its second,
     * all at once: each is read from the paths as they were before any moved,
     * so `a` to `b` with `b` to `a` swaps them. Whatever was at a path moved
     * to, and isn't moving itself, is replaced.
     * @param list<array{FieldPath, FieldPath}> $moves Each from, then to.
     */
    public function moveAll(array $moves): void {
        $moves = array_values(array_filter(
            array_map(static fn (array $move): array => [(string) $move[0], (string) $move[1]], $moves),
            static fn (array $move): bool => $move[0] !== $move[1],
        ));
        if ($moves === []) {
            return;
        }

        $this->values = self::rekeyed($this->values, $moves);
        $this->marks = self::rekeyed($this->marks, $moves);
        $this->ownedIds = self::rekeyed($this->ownedIds, $moves);
        if ($this->own !== null) {
            $this->own = self::rekeyed($this->own, $moves);
        }
    }

    /**
     * Everything at $from and under it copied to $to, replacing whatever was
     * there; $from keeps it all. Each copy is converted, so it's compared with
     * $to's default like a converter's output. An owned object copied is
     * another one: it gets new ids, so References keep pointing at the one
     * left at $from. A default instance is left as it is: a copy changes
     * entries, never what a type sets itself.
     */
    public function copy(FieldPath $from, FieldPath $to): void {
        if ($this->own !== null || (string) $from === (string) $to) {
            return;
        }

        // Keeps whatever joins the rest on: "name" copies to "title", "price:amount" to "cost:amount".
        $copied = [];
        foreach ($this->values as $key => $value) {
            if (self::isAtOrUnder((string) $key, (string) $from)) {
                $copied[(string) $to . substr((string) $key, strlen((string) $from))] = $value;
            }
        }

        $this->removeAt($to);
        foreach ($copied as $key => $value) {
            $this->set(FieldPath::fromString((string) $key), $value, ValueMark::Converted);
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
    /**
     * Removes the Collection at $path's items holding null, closing the gaps:
     * what's left keeps its order. A Collection following its default is
     * left alone: its items are the default's.
     */
    public function removeNullItems(FieldPath $path): void {
        $mark = $this->markOf($path);
        if ($mark->isDefault() || !$this->has($path)) {
            return;
        }

        $count = (int) $this->get($path);
        $kept = 0;
        for ($index = 0; $index < $count; $index++) {
            $item = $path->traverse($index);
            if ($this->get($item) === null) {
                $this->removeAt($item);
                continue;
            }

            $this->move($item, $path->traverse($kept));
            $kept++;
        }

        $this->set($path, $kept, $mark);
    }

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

    /**
     * Captures field $field of $occurrence under record $recordId, then
     * removes it: its value, everything under it, and the owned objects it
     * holds, whose ids the capture keeps. A default instance only removes it:
     * it has no entity to keep a capture on.
     */
    public function setAside(string $recordId, Occurrence $occurrence, FieldName $field): void {
        $path = (string) $occurrence->fieldPath($field);
        if ($this->own === null) {
            $capture = ['values' => [], 'touched' => [], 'ownedIds' => []];
            foreach ($this->values as $key => $value) {
                $relative = $this->relativeToField((string) $key, $path);
                if ($relative !== null) {
                    $capture['values'][$relative] = $value;
                    if ($this->marks[$key] !== ValueMark::Untouched) {
                        $capture['touched'][] = $relative;
                    }
                }
            }

            foreach ($this->ownedIds as $key => $id) {
                $relative = $this->relativeToField((string) $key, $path);
                if ($relative !== null) {
                    $capture['ownedIds'][$relative] = $id;
                }
            }

            [$holder, $site] = $this->holderOf($occurrence);
            $this->setAside[$holder][$recordId][$site] = $capture;
        }

        $this->removeAt(FieldPath::fromString($path));
    }

    /**
     * Every field set aside, captured.
     * @return array<string, array<string, array<string, array{values: array<string, mixed>, touched: list<string>, ownedIds: array<string, string>}>>>
     *         By holding entity ("" for the root, an owned object's id otherwise),
     *         then record id, then site inside the entity.
     */
    public function setAsideCaptures(): array {
        return $this->setAside;
    }

    /** $key relative to the field at $path: "" for the field itself, null when not at or under it. */
    private function relativeToField(string $key, string $path): ?string {
        if ($key === $path) {
            return '';
        }

        foreach ([FieldPath::EMBED, FieldPath::TRAVERSE] as $join) {
            if (str_starts_with($key, $path . $join)) {
                return substr($key, strlen($path));
            }
        }

        return null;
    }

    /**
     * The entity $occurrence lives on, and where it sits inside it: the root
     * itself, an owned object itself, or an Embed inside the nearest of them
     * holding it.
     * @return array{string, string} The entity ("" for the root, an owned
     *         object's id otherwise), and the site ("" for the entity itself).
     */
    private function holderOf(Occurrence $occurrence): array {
        if ($occurrence->kind === OccurrenceKind::Root || $occurrence->path === null) {
            return ['', ''];
        }

        $path = (string) $occurrence->path;
        if ($occurrence->kind === OccurrenceKind::Owned) {
            return [$this->ownedIds[$path] ?? '', ''];
        }

        // An Embed lives inside its nearest owned holder, or the root.
        $holder = null;
        foreach (array_keys($this->ownedIds) as $slot) {
            $slot = (string) $slot;
            if (self::relativeTo($path, $slot) !== null && ($holder === null || strlen($slot) > strlen($holder))) {
                $holder = $slot;
            }
        }

        return $holder === null ? ['', $path] : [$this->ownedIds[$holder], (string) self::relativeTo($path, $holder)];
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
     * @param list<array{string, string}> $moves Each from, then to.
     * @return array<string, T> $entries with every key at or under a move's
     *         from moved under its to, all at once, and every other key at or
     *         under a to dropped: it's replaced.
     */
    private static function rekeyed(array $entries, array $moves): array {
        // In one pass, in order: what a move replaces goes unless it moves
        // away itself, so a key moved never meets one kept.
        $rekeyed = [];
        foreach ($entries as $key => $entry) {
            $key = (string) $key;
            $move = self::moveOf($key, $moves);
            if ($move !== null) {
                // Keeps whatever joins the rest on: "price:amount" moves to "cost:amount".
                $rekeyed[$move[1] . substr($key, strlen($move[0]))] = $entry;
            } elseif (!self::isMovedOnto($key, $moves)) {
                $rekeyed[$key] = $entry;
            }
        }

        return $rekeyed;
    }

    /**
     * The move whose first path `$key` is at or under; null for none.
     * @param list<array{string, string}> $moves
     * @return array{string, string}|null
     */
    private static function moveOf(string $key, array $moves): ?array {
        foreach ($moves as $move) {
            if (self::isAtOrUnder($key, $move[0])) {
                return $move;
            }
        }

        return null;
    }

    /**
     * Whether `$key` is at or under a path some move goes to.
     * @param list<array{string, string}> $moves
     */
    private static function isMovedOnto(string $key, array $moves): bool {
        foreach ($moves as $move) {
            if (self::isAtOrUnder($key, $move[1])) {
                return true;
            }
        }

        return false;
    }
}
