<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/** Rules on types the checker and the built-ins share. */
final class TypeRules
{
    /** Numbers widen this way only: an int becomes a decimal or a float, a decimal a float. */
    private const NUMBER_RANK = ['int' => 0, 'decimal' => 1, 'float' => 2];

    /** The number type both $a and $b fit, null or not as they are. */
    public static function widerNumber(Type $a, Type $b): Type {
        $wider = self::NUMBER_RANK[$a->scalar->value] >= self::NUMBER_RANK[$b->scalar->value] ? $a : $b;

        return $a->nullable || $b->nullable ? $wider->nonNull()->orNull() : $wider->nonNull();
    }

    /**
     * The type both branches of a condition give: never and null fold into
     * the other, numbers widen, a string meets a text as a text. Null when
     * they don't meet.
     */
    public static function unify(Type $a, Type $b): ?Type {
        if ($a->kind === TypeKind::Never || $a->kind === TypeKind::Default) {
            return $b;
        }

        if ($b->kind === TypeKind::Never || $b->kind === TypeKind::Default) {
            return $a;
        }

        if ($a->kind === TypeKind::Null) {
            return $b->kind === TypeKind::Null ? $a : $b->orNull();
        }

        if ($b->kind === TypeKind::Null) {
            return $a->orNull();
        }

        $nullable = $a->nullable || $b->nullable;
        $met = match (true) {
            $a->isNumber() && $b->isNumber() => self::widerNumber($a, $b),
            $a->isText() && $b->isText() => $a->is(ScalarType::String) && $b->is(ScalarType::String) ? $a : Type::text(),
            $a->kind === TypeKind::List && $b->kind === TypeKind::List => ($item = self::unify($a->item, $b->item)) === null ? null : Type::list($item),
            $a->nonNull()->equals($b->nonNull()) => $a,
            default => null,
        };

        return $met === null ? null : ($nullable ? $met->orNull() : $met->nonNull());
    }

    /** How Runtime names $type's scalar: "int", "decimal", "text". */
    public static function runtimeName(Type $type): string {
        return match ($type->scalar) {
            ScalarType::String, ScalarType::Text => 'text',
            ScalarType::DateTime => 'datetime',
            default => $type->scalar->value,
        };
    }
}
