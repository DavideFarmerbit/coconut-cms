<?php

namespace XyloIsCoding\CoconutCms\Utils;

use JsonException;
use RuntimeException;

/**
 * Resolves hashed asset URLs from a Vite manifest.json.
 */
final class AssetTracker
{
    /** @var array<string, ManifestEntry>|null manifest entry key to ManifestEntry */
    private ?array $manifest = null;

    /**
     * @param string $manifestPath path to the Vite manifest.json file
     * @param string $baseUrl base URL of the generated assets (e.g. "/dist/")
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * URL of the hashed asset file.
     *
     * @param string $entryKey
     * @return string
     */
    public function url(string $entryKey): string
    {
        return $this->baseUrl . $this->entry($entryKey)->file;
    }

    /**
     * URLs of the CSS files Vite extracted for this entry (e.g. a .scss
     * imported by a JS entrypoint).
     *
     * @param string $entryKey
     * @return list<string>
     */
    public function css(string $entryKey): array
    {
        return array_values(array_map(
            fn (string $file): string => $this->baseUrl . $file,
            $this->entry($entryKey)->css,
        ));
    }

    /**
     * Gets the manifest entry for the given asset path.
     *
     * @param string $entryKey
     * @return ManifestEntry
     */
    private function entry(string $entryKey): ManifestEntry
    {
        $manifest = $this->manifest ??= $this->loadManifest();

        if (!isset($manifest[$entryKey])) {
            throw new RuntimeException("Asset entry \"{$entryKey}\" not found in manifest \"{$this->manifestPath}\".");
        }

        return $manifest[$entryKey];
    }

    /** 
     * Reads Vite's manifest.json file.
     * 
     * @return array<string, ManifestEntry> 
     */
    private function loadManifest(): array
    {
        if (!is_file($this->manifestPath)) {
            throw new RuntimeException("Vite manifest not found at \"{$this->manifestPath}\".");
        }

        try {
            /** @var array<string, array{file: string, css?: list<string>, imports?: list<string>}> $raw */
            $raw = json_decode(
                file_get_contents($this->manifestPath),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException("Vite manifest at \"{$this->manifestPath}\" is not valid JSON.", previous: $exception);
        }

        return array_map(ManifestEntry::fromArray(...), $raw);
    }
}
