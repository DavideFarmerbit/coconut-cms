<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Splits change-language text into tokens, one at a time, for the parser.
 * A type name holds characters a token can't (`coconut-cms:assets.frontend`),
 * so the parser asks for one with typeName() where a type is expected.
 */
final class Lexer
{
    /** Longest first, so `?.` is never read as `?`. */
    private const SYMBOLS = ['?.', '??', '=>', '==', '!=', '<=', '>=', '{', '}', '(', ')', '[', ']', ',', '.', '<', '>', '=', '+', '-', '*', '/', '%', '~', '?', ':'];

    private const TYPE_NAME = '/\G[A-Za-z0-9_][A-Za-z0-9_.\-]*:[A-Za-z0-9_][A-Za-z0-9_.\-]*/';

    private int $offset = 0;

    private ?Token $peeked = null;

    /** @var list<int> The offset each line starts at, first line first. */
    private readonly array $lineStarts;

    public function __construct(
        private readonly string $source,
    ) {
        preg_match_all('/\n/', $source, $newlines, PREG_OFFSET_CAPTURE);
        $this->lineStarts = [0, ...array_map(static fn (array $newline): int => $newline[1] + 1, $newlines[0])];
    }

    /** The next token, left to read. */
    public function peek(): Token {
        return $this->peeked ??= $this->scan();
    }

    /** The next token, read. */
    public function next(): Token {
        $token = $this->peek();
        $this->peeked = null;

        return $token;
    }

    /** Reads again from $offset, a token's start already read. */
    public function rewindTo(int $offset): void {
        $this->offset = $offset;
        $this->peeked = null;
    }

    /** A type name at this point: `shop:product`, or one in backticks. */
    public function typeName(): string {
        if ($this->peeked !== null) {
            $this->offset = $this->peeked->offset;
            $this->peeked = null;
        }

        $this->skipSpace();
        if (($this->source[$this->offset] ?? '') === '`') {
            return $this->quoted()->text;
        }

        if (preg_match(self::TYPE_NAME, $this->source, $match, 0, $this->offset) !== 1) {
            throw $this->error('Expected a type name, like shop:product.', $this->offset);
        }

        $this->offset += strlen($match[0]);

        return $match[0];
    }

    /** An error at $offset. */
    public function error(string $message, int $offset): SyntaxError {
        [$line, $column] = $this->position($offset);

        return new SyntaxError($message, $line, $column);
    }

    /**
     * The line and column of $offset, both from 1.
     * @return array{int, int}
     */
    private function position(int $offset): array {
        $low = 0;
        $high = count($this->lineStarts) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return [$low + 1, $offset - $this->lineStarts[$low] + 1];
    }

    private function scan(): Token {
        $this->skipSpace();
        $start = $this->offset;
        $char = $this->source[$start] ?? '';

        if ($char === '') {
            return $this->token(TokenKind::End, '', $start);
        }

        if ($char === '`') {
            return $this->quoted();
        }

        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $this->source, $match, 0, $start) === 1) {
            $this->offset += strlen($match[0]);

            return $this->token(TokenKind::Word, $match[0], $start);
        }

        if (preg_match('/\G\d+(\.\d+)?/', $this->source, $match, 0, $start) === 1) {
            $this->offset += strlen($match[0]);

            return $this->token(TokenKind::Number, $match[0], $start);
        }

        if ($char === '"') {
            return $this->text();
        }

        if ($char === '@') {
            return $this->date();
        }

        foreach (self::SYMBOLS as $symbol) {
            if (substr_compare($this->source, $symbol, $start, strlen($symbol)) === 0) {
                $this->offset += strlen($symbol);

                return $this->token(TokenKind::Symbol, $symbol, $start);
            }
        }

        throw $this->error(sprintf('Unexpected character "%s".', $char), $start);
    }

    /** Skips whitespace and `//` comments. */
    private function skipSpace(): void {
        while (preg_match('/\G(?:\s+|\/\/[^\n]*)/', $this->source, $match, 0, $this->offset) === 1 && $match[0] !== '') {
            $this->offset += strlen($match[0]);
        }
    }

    /** `` `any name` ``, a backtick written twice inside. */
    private function quoted(): Token {
        $start = $this->offset;
        if (preg_match('/\G`((?:[^`]|``)+)`/', $this->source, $match, 0, $start) !== 1) {
            throw $this->error('A quoted name needs a closing backtick and at least one character.', $start);
        }

        $this->offset += strlen($match[0]);

        return $this->token(TokenKind::Word, str_replace('``', '`', $match[1]), $start, true);
    }

    /** `"…"`, with \", \\, \n, \r, \t and \u{…}. */
    private function text(): Token {
        $start = $this->offset;
        $text = '';
        for ($at = $start + 1; ; $at++) {
            $char = $this->source[$at] ?? '';
            if ($char === '' || $char === "\n") {
                throw $this->error('A text needs its closing quote on the same line.', $start);
            }

            if ($char === '"') {
                $this->offset = $at + 1;

                return $this->token(TokenKind::Text, $text, $start);
            }

            if ($char !== '\\') {
                $text .= $char;
                continue;
            }

            $escape = $this->source[++$at] ?? '';
            if ($escape === 'u' && preg_match('/\G\{([0-9A-Fa-f]{1,6})\}/', $this->source, $match, 0, $at + 1) === 1) {
                $code = hexdec($match[1]);
                if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    throw $this->error(sprintf('\\u{%s} is not a character.', $match[1]), $at - 1);
                }

                $text .= mb_chr($code, 'UTF-8');
                $at += strlen($match[0]);
                continue;
            }

            $text .= match ($escape) {
                '"' => '"',
                '\\' => '\\',
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                default => throw $this->error(sprintf('Unknown escape "\\%s".', $escape), $at - 1),
            };
        }
    }

    /** `@2024-01-31`, or `@2024-01-31T10:00:00Z` with Z or an offset, held in UTC. */
    private function date(): Token {
        $start = $this->offset;
        $pattern = '/\G@(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2}))?/';
        if (preg_match($pattern, $this->source, $match, 0, $start) !== 1) {
            throw $this->error('A date is written @2024-01-31, a datetime @2024-01-31T10:00:00Z.', $start);
        }

        $this->offset += strlen($match[0]);
        if (!isset($match[2])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
            if ($date === false || $date->format('Y-m-d') !== $match[1]) {
                throw $this->error(sprintf('%s is not a date.', $match[1]), $start);
            }

            return $this->token(TokenKind::Date, $match[1], $start);
        }

        $written = $match[1] . 'T' . $match[2] . ($match[3] === 'Z' ? '+00:00' : $match[3]);
        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $written);
        if ($dateTime === false || $dateTime->format('Y-m-d\TH:i:sP') !== $written) {
            throw $this->error(sprintf('%s is not a datetime.', substr($match[0], 1)), $start);
        }

        return $this->token(TokenKind::DateTime, $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $start);
    }

    private function token(TokenKind $kind, string $text, int $offset, bool $quoted = false): Token {
        [$line, $column] = $this->position($offset);

        return new Token($kind, $text, $offset, $line, $column, $quoted);
    }
}
