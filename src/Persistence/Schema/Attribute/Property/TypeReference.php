<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as a type reference, as Unreal's TSubclassOf: it
 * holds the identifier of $base, or of a type extending it, native or
 * editor-made. Declared as a string, nullable when it may hold no type.
 * Migrations rename what it holds with the type.
 *
 *     #[TypeReference(GameItem::class)]
 *     public ?string $itemClass = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class TypeReference
{
    /** @param class-string $base The #[Entity]-marked class every value is, or extends. */
    public function __construct(
        public string $base,
    ) {
    }
}
