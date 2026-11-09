<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

/** One token, with where it starts. */
final readonly class Token
{
    /** @param bool $quoted A word written in backticks: never a keyword. */
    public function __construct(
        public TokenKind $kind,
        public string $text,
        public int $offset,
        public int $line,
        public int $column,
        public bool $quoted = false,
    ) {
    }

    /** Whether it's this symbol. */
    public function isSymbol(string $symbol): bool {
        return $this->kind === TokenKind::Symbol && $this->text === $symbol;
    }

    /** Whether it's this keyword: a bare word, never a quoted one. */
    public function isKeyword(string $keyword): bool {
        return $this->kind === TokenKind::Word && !$this->quoted && $this->text === $keyword;
    }
}
