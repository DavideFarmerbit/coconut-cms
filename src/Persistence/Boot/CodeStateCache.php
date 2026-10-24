<?php

namespace XyloIsCoding\CoconutCms\Persistence\Boot;

use JsonException;

/**
 * Remembers what reading the code found, across requests: its StateKey, and
 * the classes to register. A request then learns them by checking a few
 * files' modification time and size, instead of reading and compiling every
 * class. Valid while the same modules are booted and none of the files it
 * watches changed.
 *
 * Kept as JSON, never as PHP: opcache with timestamp checks off would keep
 * serving a rewritten PHP file's old content.
 */
final readonly class CodeStateCache
{
    private const FILE = 'coconut-cms-state.json';

    /** @param string|null $directory A writable directory; null remembers nothing. */
    public function __construct(
        private ?string $directory,
    ) {
    }

    /**
     * What was stored for $modules, null when there's nothing, or when a
     * watched file changed since.
     * @param list<string> $modules The booted modules' class names, in order.
     * @return array<string, mixed>|null
     */
    public function read(array $modules): ?array {
        $cached = $this->load();
        if ($cached === null || $cached['modules'] !== $modules || !self::unchanged($cached['files'])) {
            return null;
        }

        return $cached['state'];
    }

    /**
     * Stores $state for $modules, valid while none of $files changes. Written
     * whole, through a temporary file, so a request reading it at the same
     * time never sees half of it.
     * @param list<string> $modules
     * @param array<string, mixed> $state Anything json_encode() keeps as is.
     * @param list<string> $files
     */
    public function write(array $modules, array $state, array $files): void {
        if ($this->directory === null) {
            return;
        }

        $stamps = [];
        foreach (array_unique($files) as $file) {
            $stamps[$file] = self::stampOf($file);
        }

        $temporary = tempnam($this->directory, 'coconut-cms-state');
        file_put_contents($temporary, json_encode(['modules' => $modules, 'files' => $stamps, 'state' => $state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        rename($temporary, $this->path());
    }

    /**
     * The cache file's contents, null when there's none or it's unreadable.
     * @return array{modules: list<string>, files: array<string, string>, state: array<string, mixed>}|null
     */
    private function load(): ?array {
        if ($this->directory === null) {
            return null;
        }

        $contents = @file_get_contents($this->path());
        if ($contents === false) {
            return null;
        }

        try {
            $cached = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($cached) && isset($cached['modules'], $cached['files'], $cached['state']) ? $cached : null;
    }

    /** @param array<string, string> $stamps Each file's stamp, keyed by path. */
    private static function unchanged(array $stamps): bool {
        foreach ($stamps as $file => $stamp) {
            if (self::stampOf($file) !== $stamp) {
                return false;
            }
        }

        return true;
    }

    /** $file's modification time and size; "missing" once it's gone. */
    private static function stampOf(string $file): string {
        $stat = @stat($file);

        return $stat === false ? 'missing' : $stat['mtime'] . ':' . $stat['size'];
    }

    private function path(): string {
        return rtrim($this->directory, '/') . '/' . self::FILE;
    }
}
