<?php

namespace XyloIsCoding\CoconutCms\Api\Output;

/** A table of text, as the command line prints a ForHumans result. */
final readonly class TextTable
{
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows Each as many cells as $headers.
     * @param string|null $footer A line printed under it: "More: --after=...".
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public ?string $footer = null,
    ) {
    }

    /** The table as lines of padded columns. */
    public function render(): string {
        $widths = array_map(mb_strlen(...), $this->headers);
        foreach ($this->rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($cell));
            }
        }

        $line = static fn (array $cells): string => rtrim(implode('  ', array_map(
            static fn (string $cell, int $column): string => $cell . str_repeat(' ', $widths[$column] - mb_strlen($cell)),
            $cells,
            array_keys($cells),
        )));

        $lines = [$line($this->headers), $line(array_map(static fn (int $width): string => str_repeat('-', $width), $widths))];
        foreach ($this->rows as $row) {
            $lines[] = $line($row);
        }

        if ($this->footer !== null) {
            $lines[] = '';
            $lines[] = $this->footer;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
