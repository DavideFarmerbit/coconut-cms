<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

use LogicException;
use PhpToken;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reads the types a constructor's @param tags give its array parameters,
 * class names resolved the way PHP resolves them in that file: what tells
 * InputReader a list's items, and ResultWriter a map from a list. Knows
 * `list<T>`, `T[]`, `array<int, T>` and `array<string, T>`.
 */
final class DocTypes
{
    /** @var array<string, array<string, string>> Each file's imports, by file. */
    private static array $imports = [];

    /**
     * Each @param of $method's doc comment, by parameter name, as written.
     * @return array<string, string>
     */
    public static function paramsOf(ReflectionMethod $method): array {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return [];
        }

        // A type may hold spaces after commas: "array<string, mixed>".
        preg_match_all('/@param\s+((?:[^\s<]|<[^>]*(?:<[^>]*>[^>]*)*>)+)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as [, $type, $name]) {
            $params[$name] = $type;
        }

        return $params;
    }

    /**
     * The item type of an array type, as written: "ConditionInput" for
     * `list<ConditionInput>`; null when $type isn't one of the forms known.
     */
    public static function listItemOf(string $type): ?string {
        if (preg_match('/^(?:list<(.+)>|array<int,\s*(.+)>|([^<>\s]+)\[\])$/', $type, $parts) !== 1) {
            return null;
        }

        return trim($parts[1] !== '' ? $parts[1] : ($parts[2] ?? '') . ($parts[3] ?? ''));
    }

    /** Whether $type is a map keyed by strings: `array<string, T>`. */
    public static function isStringMap(string $type): bool {
        return preg_match('/^array<string,/', $type) === 1;
    }

    /** The value type of a map type, as written: "mixed" for `array<string, mixed>`; null for any other type. */
    public static function mapValueOf(string $type): ?string {
        return preg_match('/^array<string,\s*(.+)>$/', $type, $parts) === 1 ? trim($parts[1]) : null;
    }

    /** $name, a class name as written in $context's file, fully qualified. */
    public static function resolveClass(ReflectionClass $context, string $name): string {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $imports = self::importsOf($context);
        $first = explode('\\', $name)[0];
        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)] . substr($name, strlen($first));
        }

        $namespace = $context->getNamespaceName();

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * The class imports of $context's file, by lowercase alias.
     * @return array<string, string>
     */
    private static function importsOf(ReflectionClass $context): array {
        $file = $context->getFileName();
        if ($file === false) {
            throw new LogicException(sprintf('%s has no file to read its imports from.', $context->getName()));
        }

        return self::$imports[$file] ??= self::parseImports((string) file_get_contents($file));
    }

    /** @return array<string, string> */
    private static function parseImports(string $source): array {
        $imports = [];
        $depth = 0;
        $tokens = PhpToken::tokenize($source);
        foreach ($tokens as $index => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }

            // A "use" inside braces is a trait's or a closure's, never an import.
            if ($depth !== 0 || !$token->is(T_USE)) {
                continue;
            }

            $statement = '';
            for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next]->text !== ';'; $next++) {
                $statement .= $tokens[$next]->text;
            }

            foreach (self::importsIn(trim($statement)) as $alias => $class) {
                $imports[$alias] = $class;
            }
        }

        return $imports;
    }

    /**
     * The classes one use statement imports, by lowercase alias: "A\B",
     * "A\B as C", "A\{B, C as D}". Functions and constants are skipped.
     * @return array<string, string>
     */
    private static function importsIn(string $statement): array {
        if (preg_match('/^(function|const)\s/i', $statement) === 1) {
            return [];
        }

        $names = [];
        if (preg_match('/^([^{]+)\{(.*)\}$/s', $statement, $group) === 1) {
            foreach (explode(',', $group[2]) as $member) {
                $names[] = rtrim(trim($group[1]), '\\') . '\\' . trim($member);
            }
        } else {
            $names = explode(',', $statement);
        }

        $imports = [];
        foreach ($names as $name) {
            $parts = preg_split('/\s+as\s+/i', trim($name));
            $class = ltrim($parts[0], '\\');
            $alias = $parts[1] ?? substr($class, (int) strrpos('\\' . $class, '\\'));
            $imports[strtolower(trim($alias))] = $class;
        }

        return $imports;
    }
}
