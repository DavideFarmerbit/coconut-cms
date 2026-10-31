<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;

/**
 * Reads values out of decoded JSON for the schema operations' forms, each
 * failure an InvalidInput naming its path: what InputReader does for typed
 * inputs, for the forms it can't type.
 */
final class Json
{
    /**
     * $data as a JSON object.
     * @return array<string, mixed>
     */
    public static function object(mixed $data, string $path): array {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidInput::at($path, 'An object is needed here.');
        }

        return $data;
    }

    /**
     * Throws for a key of $data not in $allowed: a typo never passes silently.
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    public static function onlyKeys(array $data, array $allowed, string $path): void {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw InvalidInput::at(self::at($path, (string) $key), sprintf('Unknown. Known here: %s.', implode(', ', $allowed)));
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $path): string {
        $value = $data[$key] ?? throw InvalidInput::at(self::at($path, $key), 'Missing.');

        return is_string($value) ? $value : throw InvalidInput::at(self::at($path, $key), 'A string is needed here.');
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key, string $path): ?string {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key, $path);
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key, string $path, bool $default): bool {
        $value = $data[$key] ?? $default;

        return is_bool($value) ? $value : throw InvalidInput::at(self::at($path, $key), 'true or false is needed here.');
    }

    /** $key under $path: "operations.2" and "type" give "operations.2.type". */
    public static function at(string $path, string $key): string {
        return $path === '' ? $key : "$path.$key";
    }
}
