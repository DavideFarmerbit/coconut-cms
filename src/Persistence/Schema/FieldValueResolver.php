<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;

/**
 * Finds the effective value of one FieldPath on one identifier, native or
 * editor-created alike. Checks the identifier and each of its parents for the
 * path as stored. Failing that, falls back through the path's first field: an
 * Embed to its target type, an OwningReference or an owned Collection item to
 * whatever class it currently holds, itself resolved the same way first.
 */
final class FieldValueResolver
{
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly SchemaFieldValueStore $store,
    ) {
    }

    /** Throws when nothing along the way holds a value for $path. */
    public function resolve(string $identifier, FieldPath $path): mixed {
        for ($scope = $identifier; $scope !== null; $scope = $this->registry->get($scope)->parent) {
            $values = $this->store->load($scope);
            if (array_key_exists((string) $path, $values)) {
                return $values[(string) $path];
            }
        }

        return $this->resolveThroughHead($identifier, $path);
    }

    /** Falls back through $path's first field, once $identifier's own chain had nothing for it. */
    private function resolveThroughHead(string $identifier, FieldPath $path): mixed {
        $head = $path->head();
        $field = $this->fieldOf($identifier, $head, $path);
        $tail = $path->tail();

        if ($tail === null) {
            throw $this->noValue($identifier, $path);
        }

        return match ($field->kind()) {
            FieldKind::Embed => $this->resolve($field->target(), $this->tailAfter($identifier, $path, FieldPath::EMBED)),
            FieldKind::OwningReference => $this->resolveThroughOwned($identifier, FieldPath::of($head), $this->tailAfter($identifier, $path, FieldPath::TRAVERSE), $path),
            FieldKind::Collection => $this->resolveThroughItem($identifier, $head, $field->itemDescriptor(), $this->tailAfter($identifier, $path, FieldPath::TRAVERSE), $path),
            default => throw $this->invalidPath($identifier, $path, sprintf('"%s" has no fields of its own', $head)),
        };
    }

    /** $itemPath starts with the item's index: "0.horsepower". */
    private function resolveThroughItem(string $identifier, FieldName $collection, FieldDescriptor $item, FieldPath $itemPath, FieldPath $path): mixed {
        $index = $itemPath->head();
        if (!is_int($index)) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" must be followed by an item index', $collection));
        }

        // The bare collection path holds its item count.
        $count = $this->resolve($identifier, FieldPath::of($collection));
        if ($index >= $count) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" has only %d item(s)', $collection, $count));
        }

        $rest = $itemPath->tail();
        if ($rest === null) {
            throw $this->noValue($identifier, $path);
        }

        return match ($item->kind()) {
            FieldKind::Embed => $this->resolve($item->target(), $rest),
            FieldKind::OwningReference => $this->resolveThroughOwned($identifier, FieldPath::of($collection)->traverse($index), $rest, $path),
            default => throw $this->invalidPath($identifier, $path, sprintf('the items of "%s" have no fields of their own', $collection)),
        };
    }

    /**
     * Resolves $rest on whatever class the OwningReference at $ownerPath holds.
     * $rest is taken already checked, so a malformed path is reported as such
     * before anything gets looked up.
     */
    private function resolveThroughOwned(string $identifier, FieldPath $ownerPath, FieldPath $rest, FieldPath $path): mixed {
        return $this->resolve($this->ownedClass($identifier, $ownerPath, $path), $rest);
    }

    /** The class an OwningReference at $ownerPath currently holds, itself resolved on $identifier. */
    private function ownedClass(string $identifier, FieldPath $ownerPath, FieldPath $path): string {
        $class = $this->resolve($identifier, $ownerPath);
        if ($class === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot resolve "%s" on "%s": "%s" holds nothing.',
                $path,
                $identifier,
                $ownerPath,
            ));
        }

        return $class;
    }

    /** $path's tail, after checking $path's head joins to it with $join. */
    private function tailAfter(string $identifier, FieldPath $path, string $join): FieldPath {
        if ($path->headJoin() !== $join) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" must be followed by "%s"', $path->head(), $join));
        }

        return $path->tail();
    }

    private function fieldOf(string $identifier, FieldName|int $head, FieldPath $path): FieldDescriptor {
        $fields = $this->hierarchyFieldsOf($identifier);
        if (!$head instanceof FieldName || !$fields->has($head)) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" has no field "%s"', $identifier, $head));
        }

        return $fields->get($head);
    }

    /** $identifier's own fields plus everything it inherits, all the way up. */
    private function hierarchyFieldsOf(string $identifier): FieldDescriptorCollection {
        $schema = $this->registry->get($identifier);
        $inherited = $schema->parent !== null ? $this->hierarchyFieldsOf($schema->parent) : FieldDescriptorCollection::empty();

        return $inherited->merge($schema->fields);
    }

    private function noValue(string $identifier, FieldPath $path): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('Nothing holds a value for "%s" on "%s".', $path, $identifier));
    }

    private function invalidPath(string $identifier, FieldPath $path, string $reason): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" is not a valid path on "%s": %s.', $path, $identifier, $reason));
    }
}
