<?php

namespace XyloIsCoding\CoconutCms\Utils;

final readonly class ManifestEntry
{
    /**
     * @param list<string> $css
     * @param list<string> $imports
     */
    public function __construct(
        public string $file,
        public array  $css = [],
        public array  $imports = [],
    ) {
    }

    /** @param array{file: string, css?: list<string>, imports?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            file: $data['file'],
            css: $data['css'] ?? [],
            imports: $data['imports'] ?? [],
        );
    }
}
